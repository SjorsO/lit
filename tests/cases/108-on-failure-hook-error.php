<?php

require __DIR__.'/../test-helpers.php';

// Test that if on-failure hook fails, the deploy continues and logs a message

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

// Make before-release hook fail to trigger on-failure
file_put_contents("$projectPath/hooks/before-release.sh", "exit 1\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");

// Make on-failure hook also fail
file_put_contents("$projectPath/hooks/on-failure.sh", "exit 1\n");

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

[$statusCode, $output] = lit('deploy');

// Deploy should fail (due to before-release hook)
assert_same(1, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "$projectPath/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Deleting new but unreleased release directory "$projectPath/releases/1"
The on-failure hook failed
Finished with errors (in X seconds)
EXPECTED, $output);

// Log should contain the failure (on-failure hook failure is shown in stdout, not lit.log)
assert_string_contains(file_get_contents("$projectPath/logs/lit.log"), 'lit deploy → failed');

// Output should NOT contain getcwd error (would happen if we're still in deleted directory)
assert_string_not_contains($output, 'cannot access parent directories');
