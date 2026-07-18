<?php

require __DIR__.'/../test-helpers.php';

// Test that if after-release hook fails, the release is still released but script exits with error

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

// Make after-release hook fail
file_put_contents("$projectPath/hooks/before-release.sh", "\n");
file_put_contents("$projectPath/hooks/after-release.sh", "exit 1\n");

// Set up on-failure hook to record that it was called
file_put_contents("$projectPath/hooks/on-failure.sh", 'echo "$2" > "$1/on-failure-called"'."\n");

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

[$statusCode, $output] = lit('deploy');

// Deploy should fail (due to after-release hook)
assert_same(1, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "$projectPath/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/1"
>
> Warning: The new deployment was still released!
>
Finished with errors (in X seconds)
EXPECTED, $output);

// But release should still exist and be released
assert_directory_exists("$projectPath/releases/1");
assert_symlink("$projectPath/current");
assert_string_contains(readlink("$projectPath/current"), 'releases/1');

// on-failure hook should have been called with was_released=true
assert_file_exists("$projectPath/on-failure-called");
assert_file_content("$projectPath/on-failure-called", 'true');
