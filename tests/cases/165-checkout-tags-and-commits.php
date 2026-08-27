<?php

require __DIR__.'/../test-helpers.php';

// Test checking out tags and commit SHAs. Uses a local git repository
// as the remote so the test controls the branches and tags.

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

// An annotated tag on the first commit
run_process([...$gitCommand, 'tag', '-a', '-m', 'release', 'v1.0'], $seedPath);

file_put_contents("$seedPath/app.txt", "two\n");
run_process(['git', 'add', 'app.txt'], $seedPath);
run_process([...$gitCommand, 'commit', '--quiet', '-m', 'two'], $seedPath);

[, $commitTwo] = run_process(['git', 'rev-parse', 'HEAD'], $seedPath);

// An ambiguous name: branch "v9" on the newest commit, tag "v9" on the first commit
run_process(['git', 'branch', 'v9'], $seedPath);
run_process(['git', 'tag', 'v9', $commitOne], $seedPath);

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

// Deploy the default branch first
[$statusCode] = lit('deploy');

assert_same(0, $statusCode);
assert_file_content("$projectPath/releases/1/app.txt", 'two');
assert_lit_state_value($projectPath, 'git_commit_sha', $commitTwo);

// Checkout the annotated tag - should deploy the first commit
[$statusCode, $output] = lit('checkout', 'v1.0');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "v1.0"...
Creating "$projectPath/releases/2" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_file_content("$projectPath/releases/2/app.txt", 'one');

// The annotated tag resolved to the commit it points at, not to the tag object
assert_file_content("$projectPath/lit.json", <<<EXPECTED
{
    "git_repository_url": "$remoteUrl",
    "git_ref": "v1.0",
    "git_ref_type": "tag",
    "git_commit_sha": "$commitOne",
    "git_release_caching_enabled": false
}
EXPECTED);

// Deploying the tag again skips
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading tag "v1.0" of "$remoteUrl"...
Tag "v1.0" is already deployed (COMMIT)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)
EXPECTED, $output);

// Checkout the ambiguous name - the branch wins, same behavior as git
[$statusCode, $output] = lit('checkout', 'v9');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "v9"...
Warning: "v9" is both a branch and a tag, using the branch
Creating "$projectPath/releases/3" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/3"
Finished successfully (in X seconds)
EXPECTED, $output);

// The branch points at "two", the tag points at "one"
assert_file_content("$projectPath/releases/3/app.txt", 'two');

assert_file_content("$projectPath/lit.json", <<<EXPECTED
{
    "git_repository_url": "$remoteUrl",
    "git_ref": "v9",
    "git_ref_type": "branch",
    "git_commit_sha": "$commitTwo",
    "git_release_caching_enabled": false
}
EXPECTED);

// Deploying the branch again skips
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "v9" of "$remoteUrl"...
Latest commit of "v9" is already deployed (COMMIT)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)
EXPECTED, $output);

// Checkout a commit SHA - should deploy that exact commit
[$statusCode, $output] = lit('checkout', $commitOne);

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "HASH"...
Creating "$projectPath/releases/4" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/4"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_file_content("$projectPath/releases/4/app.txt", 'one');

assert_file_content("$projectPath/lit.json", <<<EXPECTED
{
    "git_repository_url": "$remoteUrl",
    "git_ref": "$commitOne",
    "git_ref_type": "commit",
    "git_commit_sha": "$commitOne",
    "git_release_caching_enabled": false
}
EXPECTED);

// A short hash resolves to the full hash and deploys it
$shortCommitTwo = substr($commitTwo, 0, 7);

[$statusCode, $output] = lit('checkout', $shortCommitTwo);

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "$shortCommitTwo"...
Resolving short hash "$shortCommitTwo"... (HASH)
Creating "$projectPath/releases/5" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/5"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_file_content("$projectPath/releases/5/app.txt", 'two');

// The short hash is stored as the full hash
assert_file_content("$projectPath/lit.json", <<<EXPECTED
{
    "git_repository_url": "$remoteUrl",
    "git_ref": "$commitTwo",
    "git_ref_type": "commit",
    "git_commit_sha": "$commitTwo",
    "git_release_caching_enabled": false
}
EXPECTED);

// A short hash that doesn't exist on the remote
[$statusCode, $output] = lit('checkout', 'deadbee');

assert_same(1, $statusCode);

$output = normalize_output($output);

assert_same(<<<'EXPECTED'
Switching to "deadbee"...
Resolving short hash "deadbee"...
"deadbee" is not a branch, tag, or commit on the remote
EXPECTED, $output);
