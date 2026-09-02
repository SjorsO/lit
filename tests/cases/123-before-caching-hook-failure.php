<?php

require __DIR__.'/../test-helpers.php';

// Test deploy when before-caching.sh hook fails

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// Enable caching
[$statusCode] = lit('enable-git-release-caching');

assert_same(0, $statusCode);

// Make the before-caching hook fail
file_put_contents("$projectPath/hooks/before-caching.sh", "exit 1\n");

[$statusCode, $output] = lit('deploy');

// Should fail because hook failed
assert_same(1, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Cloning repository...
Running "lit/hooks/before-caching.sh"...
Finished with errors (in X seconds)
EXPECTED, $output);

// No release should be created
assert_file_missing("$projectPath/releases/1");

// Log should contain failure message in single-line format
assert_string_contains(file_get_contents("$projectPath/logs/lit.log"), 'lit deploy → failed');

// on-failure hook should have been called
file_put_contents("$projectPath/hooks/on-failure.sh", 'touch "$1/on-failure-ran"'."\n");

[$statusCode, $output] = lit('deploy');

assert_same(1, $statusCode);
assert_file_exists("$projectPath/on-failure-ran");
