<?php

require __DIR__.'/../test-helpers.php';

// Test that if before-release hook fails, the deploy fails and release is cleaned up

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

// Make before-release hook fail
file_put_contents("$projectPath/hooks/before-release.sh", "exit 1\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");

// Set up on-failure hook to record that it was called
file_put_contents("$projectPath/hooks/on-failure.sh", 'echo "$2" > "$1/on-failure-called"'."\n");

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// First deploy should fail
[$statusCode, $output] = lit('deploy');

assert_same(1, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "$projectPath/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Deleting new but unreleased release directory "$projectPath/releases/1"
Finished with errors (in X seconds)
EXPECTED, $output);
assert_file_missing("$projectPath/releases/1");
assert_file_missing("$projectPath/current");

// on-failure hook should have been called with was_released=false
assert_file_exists("$projectPath/on-failure-called");
assert_file_content("$projectPath/on-failure-called", 'false');

unlink("$projectPath/on-failure-called");

// Fix the hook, second deploy should succeed
file_put_contents("$projectPath/hooks/before-release.sh", "\n");

[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "$projectPath/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/1"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_directory_exists("$projectPath/releases/1");
assert_symlink("$projectPath/current");
assert_string_contains(readlink("$projectPath/current"), 'releases/1');

// on-failure hook should NOT have been called
assert_file_missing("$projectPath/on-failure-called");

// Break the hook again, third deploy should fail
file_put_contents("$projectPath/hooks/before-release.sh", "exit 1\n");

[$statusCode, $output] = lit('deploy', '--force');

assert_same(1, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Creating "$projectPath/releases/2" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Deleting new but unreleased release directory "$projectPath/releases/2"
Finished with errors (in X seconds)
EXPECTED, $output);

// Failed release should be cleaned up
assert_file_missing("$projectPath/releases/2");

// But release 1 should still exist
assert_directory_exists("$projectPath/releases/1");

// Current symlink should still point to release 1
assert_symlink("$projectPath/current");
assert_string_contains(readlink("$projectPath/current"), 'releases/1');

// on-failure hook should have been called with was_released=false
assert_file_exists("$projectPath/on-failure-called");
assert_file_content("$projectPath/on-failure-called", 'false');
