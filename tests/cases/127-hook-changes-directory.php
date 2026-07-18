<?php

require __DIR__.'/../test-helpers.php';

// Test that hooks can change directory without breaking deploy

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// Create hooks that change to a different directory
// The deploy script should not be affected by hooks changing directory
file_put_contents("$projectPath/hooks/before-release.sh", "cd /tmp && touch /tmp/before-release-ran\n");
file_put_contents("$projectPath/hooks/after-release.sh", "cd /tmp && touch /tmp/after-release-ran\n");

[$statusCode, $output] = lit('deploy');

// Deploy should succeed despite hooks changing directory
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

// Both hooks should have run
assert_file_exists('/tmp/before-release-ran');
assert_file_exists('/tmp/after-release-ran');

// Clean up
unlink('/tmp/before-release-ran');
unlink('/tmp/after-release-ran');
