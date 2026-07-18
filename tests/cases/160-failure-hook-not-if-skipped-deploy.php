<?php

require __DIR__.'/../test-helpers.php';

// Test that on-failure hook does NOT run when commit is already deployed (without --force)

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

// Empty out the hooks
file_put_contents("$projectPath/hooks/before-release.sh", "\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");

// Set up on-failure hook to record if it was called
file_put_contents("$projectPath/hooks/on-failure.sh", 'touch "$1/on-failure-called"'."\n");

chdir($projectPath);

// First deploy should succeed
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);
assert_file_missing("$projectPath/on-failure-called");

// Second deploy without --force should skip (already deployed) and NOT call on-failure
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);
assert_string_contains($output, 'already deployed');
assert_file_missing("$projectPath/on-failure-called");
