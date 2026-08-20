<?php

require __DIR__.'/../test-helpers.php';

// Test that only one lit command can run at a time

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

neutralize_hooks($projectPath);

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// Manually create the lock directory to simulate another command running
mkdir("$projectPath/lit-is-currently-running");

[$statusCode, $output] = lit('deploy');

// Command should fail
assert_same(1, $statusCode);

assert_same(<<<EXPECTED
Another Lit command is currently running for this project, aborting...
If this is wrong, manually run:
    rmdir "$projectPath/lit-is-currently-running"
EXPECTED, $output);

// Log should contain the error message in single-line format
assert_string_contains(file_get_contents("$projectPath/logs/lit.log"), 'lit deploy → aborted, another lit command is currently running');

// Lock directory should still exist (we created it, lit didn't)
assert_directory_exists("$projectPath/lit-is-currently-running");

// Clean up lock and verify lit works again
rmdir("$projectPath/lit-is-currently-running");

[$statusCode, $output] = lit('deploy');

// Now it should succeed
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

// Lit's own lock should be cleaned up after a successful deploy
assert_file_missing("$projectPath/lit-is-currently-running");
