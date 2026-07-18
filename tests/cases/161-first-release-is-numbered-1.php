<?php

require __DIR__.'/../test-helpers.php';

// Test that the first release is numbered 1 when releases directory is empty

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

// Empty out the hooks
file_put_contents("$projectPath/hooks/before-release.sh", "\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");

// Verify releases directory exists but is empty
assert_directory_exists("$projectPath/releases");
assert_same(0, count(glob("$projectPath/releases/*")));

chdir($projectPath);

[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

// First release should be numbered 1
assert_directory_exists("$projectPath/releases/1");
assert_file_missing("$projectPath/releases/0");

// Current symlink should point to release 1
assert_string_contains(readlink("$projectPath/current"), 'releases/1');

// Delete release 1 and deploy again - should use 1 again
// (current directory still exists, pointing at nothing)
run_process(['rm', '-rf', "$projectPath/releases/1"], $projectPath);

[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

// Should be numbered 1 again since releases directory was empty
assert_directory_exists("$projectPath/releases/1");
assert_string_contains(readlink("$projectPath/current"), 'releases/1');
