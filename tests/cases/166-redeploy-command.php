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
