<?php

require __DIR__.'/../test-helpers.php';

// Test the lit checkout command

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

// Empty hooks so the deploy can succeed
file_put_contents("$projectPath/hooks/before-release.sh", "\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// Checkout without branch should fail
[$statusCode, $output] = lit('checkout');

assert_same(1, $statusCode);
assert_same('usage: lit checkout <branch>', $output);

// Checkout with extra arguments should fail
[$statusCode, $output] = lit('checkout', 'main', 'extra');

assert_same(1, $statusCode);
assert_same('usage: lit checkout <branch>', $output);

// Checkout current branch should fail
[$statusCode, $output] = lit('checkout', 'main');

assert_same(1, $statusCode);
assert_same('Current branch is already "main"', $output);

// Checkout non-existent branch should fail
[$statusCode, $output] = lit('checkout', 'this-branch-does-not-exist');

assert_same(1, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to branch "this-branch-does-not-exist"...
Branch "this-branch-does-not-exist" does not exist on remote
EXPECTED, $output);

// Checkout "this-branch-is-used-in-unit-tests" should succeed and deploy
[$statusCode, $output] = lit('checkout', 'this-branch-is-used-in-unit-tests');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to branch "this-branch-is-used-in-unit-tests"...
Creating "$projectPath/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/1"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_directory_exists("$projectPath/releases/1");
assert_symlink("$projectPath/current");
assert_file_content("$projectPath/git-branch", 'this-branch-is-used-in-unit-tests');

// Switch back to main - commit hash should be reused from checkout (not fetched again by deploy)
[$statusCode, $output] = lit('checkout', 'main');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to branch "main"...
Creating "$projectPath/releases/2" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_directory_exists("$projectPath/releases/2");
assert_file_content("$projectPath/git-branch", 'main');

// Checkout on a bundle project should fail
chdir(world_path().'/case');

[$statusCode] = lit('init', 'https://example.com/releases/my-app.tar.gz');

assert_same(0, $statusCode);

chdir(world_path().'/case/my-app');

[$statusCode, $output] = lit('checkout', 'somebranch');

assert_same(1, $statusCode);
assert_same('Cannot change branches because you are not deploying from git', $output);
