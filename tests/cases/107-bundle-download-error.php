<?php

require __DIR__.'/../test-helpers.php';

// Test that if bundle download fails, it handles gracefully

[$statusCode] = lit('init', 'https://example.com/nonexistent-bundle.tar.gz');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/nonexistent-bundle';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

// Set up on-failure hook to record that it was called
file_put_contents("$projectPath/hooks/on-failure.sh", 'echo "$2" > "$1/on-failure-called"'."\n");

chdir($projectPath);

[$statusCode, $output] = lit('deploy');

// Deploy should fail
assert_same(1, $statusCode);

$output = replace_curl_errors(normalize_output($output));

assert_same(<<<EXPECTED
Checking bundle version from "https://example.com/nonexistent-bundle.tar.gz.hash"... (in X seconds)
Warning: curl: (CURL_ERROR)
Downloading bundle from "https://example.com/nonexistent-bundle.tar.gz"...
Failed to download bundle from "https://example.com/nonexistent-bundle.tar.gz"
curl: (CURL_ERROR)
Finished with errors (in X seconds)
EXPECTED, $output);

// No release should be created
assert_file_missing("$projectPath/releases/1");

// Current symlink should not exist
assert_file_missing("$projectPath/current");

// Log should contain the download failure in single-line format
assert_string_contains(file_get_contents("$projectPath/logs/lit.log"), 'lit deploy → failed to download bundle');

// on-failure hook should have been called with was_released=false
assert_file_exists("$projectPath/on-failure-called");
assert_file_content("$projectPath/on-failure-called", 'false');
