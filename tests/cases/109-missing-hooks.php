<?php

require __DIR__.'/../test-helpers.php';

// Test that missing hooks print "Wanted to run" messages

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

// Remove all hooks
unlink("$projectPath/hooks/before-release.sh");
unlink("$projectPath/hooks/after-release.sh");
unlink("$projectPath/hooks/on-failure.sh");

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

[$statusCode, $output] = lit('deploy');

// Deploy should succeed
assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "$projectPath/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Wanted to run "$projectPath/hooks/before-release.sh" but it does not exist
Releasing the new deployment "$projectPath/releases/1"
Wanted to run "$projectPath/hooks/after-release.sh" but it does not exist
Finished successfully (in X seconds)
EXPECTED, $output);

// Release should still be created and released
assert_directory_exists("$projectPath/releases/1");
assert_symlink("$projectPath/current");
