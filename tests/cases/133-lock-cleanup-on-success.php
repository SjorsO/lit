<?php

require __DIR__.'/../test-helpers.php';

// Test that lock directory is properly cleaned up after successful command

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/hooks/before-release.sh", "\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// Lock should not exist before deploy
assert_file_missing("$projectPath/lit-is-currently-running");

// Deploy should succeed
[$statusCode] = lit('deploy');

assert_same(0, $statusCode);

// Lock should be cleaned up after deploy
assert_file_missing("$projectPath/lit-is-currently-running");
