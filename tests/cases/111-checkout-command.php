<?php

require __DIR__.'/../test-helpers.php';

// Test the lit checkout command

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

neutralize_hooks($projectPath);

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

$dotenvHash = sha1_file("$projectPath/.env");

chdir($projectPath);

// Checkout without branch should fail
[$statusCode, $output] = lit('checkout');

assert_same(1, $statusCode);
assert_same('usage: lit checkout <branch|tag|commit>', $output);

// Checkout with extra arguments should fail
[$statusCode, $output] = lit('checkout', 'main', 'extra');

assert_same(1, $statusCode);
assert_same('usage: lit checkout <branch|tag|commit>', $output);

// Checkout current branch should fail
[$statusCode, $output] = lit('checkout', 'main');

assert_same(1, $statusCode);
assert_same('"main" is already checked out', $output);

// Checkout non-existent branch should fail
[$statusCode, $output] = lit('checkout', 'this-branch-does-not-exist');

assert_same(1, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "this-branch-does-not-exist"...
"this-branch-does-not-exist" is not a branch, tag, or commit on the remote
EXPECTED, $output);

// Checkout "this-branch-is-used-in-unit-tests" should succeed and deploy
[$statusCode, $output] = lit('checkout', 'this-branch-is-used-in-unit-tests');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "this-branch-is-used-in-unit-tests"...
Creating "$projectPath/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/1"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_directory_exists("$projectPath/releases/1");
assert_symlink("$projectPath/current");
assert_lit_state_value($projectPath, 'git_ref', 'this-branch-is-used-in-unit-tests');
assert_lit_state_value($projectPath, 'git_ref_type', 'branch');

// Remember the deployed commit, it is checked out by SHA later in this test
$testBranchCommit = lit_state($projectPath)['git_commit_sha'];

// Switch back to main - commit hash should be reused from checkout (not fetched again by deploy)
[$statusCode, $output] = lit('checkout', 'main');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "main"...
Creating "$projectPath/releases/2" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_directory_exists("$projectPath/releases/2");
assert_lit_state_value($projectPath, 'git_ref', 'main');
assert_lit_state_value($projectPath, 'git_ref_type', 'branch');

// Checkout a full commit SHA - should deploy that exact commit
[$statusCode, $output] = lit('checkout', $testBranchCommit);

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "HASH"...
Creating "$projectPath/releases/3" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/3"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_directory_exists("$projectPath/releases/3");

assert_file_content("$projectPath/lit.json", <<<EXPECTED
{
    "git_repository_url": "https://github.com/SjorsO/lit.git",
    "git_ref": "$testBranchCommit",
    "git_ref_type": "commit",
    "git_commit_sha": "$testBranchCommit",
    "git_release_caching_enabled": false,
    "deployed_dotenv_hash": "$dotenvHash"
}
EXPECTED);

// Checkout the same commit again should fail
[$statusCode, $output] = lit('checkout', $testBranchCommit);

assert_same(1, $statusCode);
assert_same("\"$testBranchCommit\" is already checked out", $output);

// Deploying a commit again skips, the commit never changes
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<'EXPECTED'
Commit is already deployed (COMMIT)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)
EXPECTED, $output);
assert_file_missing("$projectPath/releases/4");

// A short commit hash resolves to the full hash
$shortCommit = substr($testBranchCommit, 0, 7);

[$statusCode, $output] = lit('checkout', $shortCommit);

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "$shortCommit"...
Resolving short hash "$shortCommit"... (HASH)
Commit is already deployed (COMMIT)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)
EXPECTED, $output);

// The full hash is stored, not the short one
assert_file_content("$projectPath/lit.json", <<<EXPECTED
{
    "git_repository_url": "https://github.com/SjorsO/lit.git",
    "git_ref": "$testBranchCommit",
    "git_ref_type": "commit",
    "git_commit_sha": "$testBranchCommit",
    "git_release_caching_enabled": false,
    "deployed_dotenv_hash": "$dotenvHash"
}
EXPECTED);

// Hashes shorter than 7 characters are not supported
$tinyCommit = substr($testBranchCommit, 0, 5);

[$statusCode, $output] = lit('checkout', $tinyCommit);

assert_same(1, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "$tinyCommit"...
"$tinyCommit" is not a branch, tag, or commit on the remote
EXPECTED, $output);

// Checkout on a bundle project should fail
chdir(world_path().'/case');

[$statusCode] = lit('init', 'https://example.com/releases/my-app.tar.gz');

assert_same(0, $statusCode);

chdir(world_path().'/case/my-app');

[$statusCode, $output] = lit('checkout', 'somebranch');

assert_same(1, $statusCode);
assert_same('Cannot checkout because you are not deploying from git', $output);
