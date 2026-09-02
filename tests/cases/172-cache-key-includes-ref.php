<?php

require __DIR__.'/../test-helpers.php';

// The release cache key includes the ref and ref type.
// Two refs can point at the same commit.
// Their clones differ in ".git" (HEAD and fetch refspec).
// So they must not share a cache entry.
// Uses a local git repository as the remote.

$worldPath = world_path();
$caseDir = "$worldPath/case";

$seedPath = "$caseDir/seed";
$remotePath = "$caseDir/origin-repo.git";

// "file://" makes git use the real transport, a plain path would ignore "--depth"
$remoteUrl = "file://$remotePath";

$gitCommand = ['git', '-c', 'user.email=lit@test', '-c', 'user.name=lit'];

run_process(['git', 'init', '--quiet', '--initial-branch=main', $seedPath], $caseDir);

file_put_contents("$seedPath/app.txt", "one\n");
run_process(['git', 'add', 'app.txt'], $seedPath);
run_process([...$gitCommand, 'commit', '--quiet', '-m', 'one'], $seedPath);

[, $commitOne] = run_process(['git', 'rev-parse', 'HEAD'], $seedPath);

// Cache file names use the first 12 chars of the commit
$cacheCommit = substr($commitOne, 0, 12);

// A second branch on the same commit
run_process(['git', 'branch', 'same-head'], $seedPath);

run_process(['git', 'clone', '--quiet', '--bare', $seedPath, $remotePath], $caseDir);

// Lit fetches commit SHAs, the remote must allow that (GitHub and GitLab do)
run_process(['git', '-C', $remotePath, 'config', 'uploadpack.allowAnySHA1InWant', 'true'], $caseDir);

chdir($caseDir);

[$statusCode] = lit('init', $remoteUrl);

assert_same(0, $statusCode);

$projectPath = "$caseDir/origin-repo";

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

[$statusCode] = lit('enable-git-release-caching');

assert_same(0, $statusCode);

// The hook writes a random marker, a rebuild gets a new value
file_put_contents("$projectPath/hooks/before-caching.sh", 'uuidgen > "$1/cache-marker"'."\n");

// Deploy the branch "same-head", this builds its cache entry
[$statusCode] = lit('checkout', 'same-head');

assert_same(0, $statusCode);

// The release is on the "same-head" branch
[, $releaseBranch] = run_process(['git', '-C', "$projectPath/current", 'rev-parse', '--abbrev-ref', 'HEAD'], $projectPath);

assert_same('same-head', $releaseBranch);

// Switch to "main", it points at the same commit
[$statusCode, $output] = lit('checkout', 'main');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "main"...
Latest commit of "main" is already deployed (COMMIT)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)
EXPECTED, $output);

assert_lit_state_value($projectPath, 'git_ref', 'main');

// Force deploy must not reuse the "same-head" cache entry
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "$remoteUrl"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Cached release found but for a different ref, rebuilding...
Cloning repository...
Running "origin-repo/hooks/before-caching.sh"...
Caching release...
Creating "$projectPath/releases/2" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);

// The new release is on "main"
[, $releaseBranch] = run_process(['git', '-C', "$projectPath/current", 'rev-parse', '--abbrev-ref', 'HEAD'], $projectPath);

assert_same('main', $releaseBranch);

// Both branches now have their own cache entry for the same commit
assert_same(2, count(glob("$worldPath/lit/cached-releases/$cacheCommit-*.tar")));

$mainCacheMarker = file_get_contents("$projectPath/current/cache-marker");

// Force deploy again, now the "main" entry is reused
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

assert_string_contains($output, 'Reusing deployment from cache');
assert_file_content("$projectPath/current/cache-marker", rtrim($mainCacheMarker, "\n"));

// Checkout the commit itself, same commit again but ref type "commit"
[$statusCode] = lit('checkout', $commitOne);

assert_same(0, $statusCode);

// Force deploy rebuilds again, the branch entries do not match
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

assert_string_contains($output, 'Cached release found but for a different ref, rebuilding...');

// A commit clone is detached, there is no branch
[, $releaseBranch] = run_process(['git', '-C', "$projectPath/current", 'rev-parse', '--abbrev-ref', 'HEAD'], $projectPath);

assert_same('HEAD', $releaseBranch);

// Three cache entries now, one per ref
assert_same(3, count(glob("$worldPath/lit/cached-releases/$cacheCommit-*.tar")));
