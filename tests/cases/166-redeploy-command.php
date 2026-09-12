<?php

require __DIR__.'/../test-helpers.php';

// Test the lit redeploy command, it deploys the exact same commit again.
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

run_process(['git', 'clone', '--quiet', '--bare', $seedPath, $remotePath], $caseDir);

// Redeploy fetches a commit SHA, the remote must allow that (GitHub and GitLab do)
run_process(['git', '-C', $remotePath, 'config', 'uploadpack.allowAnySHA1InWant', 'true'], $caseDir);

chdir($caseDir);

[$statusCode] = lit('init', $remoteUrl);

assert_same(0, $statusCode);

$projectPath = "$caseDir/origin-repo";

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

$dotenvHash = sha1_file("$projectPath/.env");

chdir($projectPath);

// Redeploy before any deploy should fail
[$statusCode, $output] = lit('redeploy');

assert_same(1, $statusCode);
assert_same('Nothing is deployed yet, run "lit deploy" first', $output);

// Redeploy with arguments should fail
[$statusCode, $output] = lit('redeploy', 'extra');

assert_same(1, $statusCode);
assert_same('usage: lit redeploy', $output);

// Deploy the branch
[$statusCode] = lit('deploy');

assert_same(0, $statusCode);
assert_file_content("$projectPath/releases/1/app.txt", 'one');

// Redeploy deploys the same commit again
[$statusCode, $output] = lit('redeploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Redeploying the current commit (COMMIT)
Creating "$projectPath/releases/2" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_file_content("$projectPath/releases/2/app.txt", 'one');
assert_string_contains(readlink("$projectPath/current"), 'releases/2');

// The tracked ref is still the branch
assert_file_content("$projectPath/lit.json", <<<EXPECTED
{
    "git_repository_url": "$remoteUrl",
    "git_ref": "main",
    "git_ref_type": "branch",
    "git_commit_sha": "$commitOne",
    "git_release_caching_enabled": false,
    "deployed_dotenv_hash": "$dotenvHash"
}
EXPECTED);

// Push a new commit to the remote branch
file_put_contents("$seedPath/app.txt", "two\n");
run_process(['git', 'add', 'app.txt'], $seedPath);
run_process([...$gitCommand, 'commit', '--quiet', '-m', 'two'], $seedPath);
run_process(['git', 'push', '--quiet', $remotePath, 'main'], $seedPath);

// Redeploy still deploys the old commit, not the new branch tip
[$statusCode, $output] = lit('redeploy');

assert_same(0, $statusCode);
assert_file_content("$projectPath/releases/3/app.txt", 'one');

// A normal deploy picks up the new commit
[$statusCode] = lit('deploy');

assert_same(0, $statusCode);
assert_file_content("$projectPath/releases/4/app.txt", 'two');

// Redeploy on a bundle project that never deployed should fail
chdir($caseDir);

[$statusCode] = lit('init', 'https://example.com/releases/my-app.tar.gz');

assert_same(0, $statusCode);

chdir("$caseDir/my-app");

[$statusCode, $output] = lit('redeploy');

assert_same(1, $statusCode);
assert_same('Nothing is deployed yet, run "lit deploy" first', $output);

// Repeat the redeploy checks with caching enabled for branches, tags, and commits.
[, $commitTwo] = run_process(['git', 'rev-parse', 'HEAD'], $seedPath);

foreach (['branch' => 'main', 'tag' => 'v1', 'commit' => $commitOne] as $refType => $ref) {
    run_process(['git', 'update-ref', 'refs/heads/main', $commitOne], $remotePath);
    run_process(['git', 'update-ref', 'refs/tags/v1', $commitOne], $remotePath);

    chdir($caseDir);

    [$statusCode] = lit('init', $remoteUrl, $refType);

    assert_same(0, $statusCode);

    $projectPath = "$caseDir/$refType";

    neutralize_hooks($projectPath);
    file_put_contents("$projectPath/.env", "APP_KEY=test\n");

    chdir($projectPath);

    [$statusCode] = lit('enable-git-release-caching');

    assert_same(0, $statusCode);

    $hook = 'uuidgen > "$1/cache-marker"'."\n";
    file_put_contents("$projectPath/hooks/before-caching.sh", $hook);

    [$statusCode] = $refType === 'branch' ? lit('deploy') : lit('checkout', $ref);

    assert_same(0, $statusCode);
    assert_file_content("$projectPath/current/app.txt", 'one');

    $originalMarker = file_get_contents("$projectPath/current/cache-marker");
    $originalCacheFiles = glob("$worldPath/lit/cached-releases/*.tar");

    // Moving the branch and tag must not affect which commit redeploy uses.
    run_process(['git', 'update-ref', 'refs/heads/main', $commitTwo], $remotePath);
    run_process(['git', 'update-ref', 'refs/tags/v1', $commitTwo], $remotePath);

    // A cache hit must work without any access to the remote.
    rename($remotePath, "$remotePath.offline");

    [$statusCode, $output] = lit('redeploy');

    rename("$remotePath.offline", $remotePath);

    assert_same(0, $statusCode);
    assert_string_contains($output, 'Reusing deployment from cache');
    assert_string_not_contains($output, 'rebuilding...');
    assert_string_not_contains($output, 'Cloning repository...');
    assert_file_content("$projectPath/current/app.txt", 'one');
    assert_same($originalMarker, file_get_contents("$projectPath/current/cache-marker"));
    assert_same($originalCacheFiles, glob("$worldPath/lit/cached-releases/*.tar"));
    assert_lit_state_value($projectPath, 'git_commit_sha', $commitOne);
    assert_lit_state_value($projectPath, 'git_ref', $ref);
    assert_lit_state_value($projectPath, 'git_ref_type', $refType);
    assert_lit_state_value($projectPath, 'deployed_git_cache_ref', "$refType:$ref");

    // The cache still has to match the hook, even during a redeploy.
    file_put_contents("$projectPath/hooks/before-caching.sh", $hook.'touch "$1/changed-hook"'."\n");

    [$statusCode, $output] = lit('redeploy');

    assert_same(0, $statusCode);
    assert_string_contains($output, 'Cached release found but hook changed, rebuilding...');
    assert_string_not_contains($output, 'Reusing deployment from cache');
    assert_file_exists("$projectPath/current/changed-hook");
    assert_file_content("$projectPath/current/app.txt", 'one');
    assert_lit_state_value($projectPath, 'git_commit_sha', $commitOne);

    $rebuiltMarker = file_get_contents("$projectPath/current/cache-marker");

    // A rebuilt redeploy cache is reusable too.
    [$statusCode, $output] = lit('redeploy');

    assert_same(0, $statusCode);
    assert_string_contains($output, 'Reusing deployment from cache');
    assert_same($rebuiltMarker, file_get_contents("$projectPath/current/cache-marker"));

    // A cache miss still fetches the exact commit, even after the remote ref moves.
    array_map('unlink', glob("$worldPath/lit/cached-releases/*.tar"));

    // If that commit cannot be fetched, keep the live release and its state.
    $liveRelease = readlink("$projectPath/current");
    $liveState = lit_state($projectPath);
    rename($remotePath, "$remotePath.offline");

    [$statusCode] = lit('redeploy');

    rename("$remotePath.offline", $remotePath);

    assert_same(128, $statusCode);
    assert_same($liveRelease, readlink("$projectPath/current"));
    assert_same($liveState, lit_state($projectPath));

    [$statusCode, $output] = lit('redeploy');

    assert_same(0, $statusCode);
    assert_string_contains($output, 'Cloning repository...');
    assert_file_content("$projectPath/current/app.txt", 'one');
    assert_lit_state_value($projectPath, 'git_commit_sha', $commitOne);

    // A detached redeploy build must not become a branch/tag cache entry.
    // Put the remote ref back, so the normal deploy looks up the same commit.
    run_process(['git', 'update-ref', 'refs/heads/main', $commitOne], $remotePath);
    run_process(['git', 'update-ref', 'refs/tags/v1', $commitOne], $remotePath);

    [$statusCode, $output] = lit('deploy', '--force');

    assert_same(0, $statusCode);

    if ($refType === 'commit') {
        assert_string_contains($output, 'Reusing deployment from cache');
    } else {
        assert_string_contains($output, 'Cached release found but for a different ref, rebuilding...');
        assert_string_not_contains($output, 'Reusing deployment from cache');
    }

    [, $releaseBranch] = run_process(['git', 'rev-parse', '--abbrev-ref', 'HEAD'], "$projectPath/current");

    assert_same($refType === 'branch' ? 'main' : 'HEAD', $releaseBranch);

    // An uncached deployment must not keep the previous cached build's ref.
    [$statusCode] = lit('disable-git-release-caching');

    assert_same(0, $statusCode);

    [$statusCode] = lit('redeploy');

    assert_same(0, $statusCode);
    assert_lit_state_missing($projectPath, 'deployed_git_cache_ref');

    array_map('unlink', glob("$worldPath/lit/cached-releases/*.tar"));
}
