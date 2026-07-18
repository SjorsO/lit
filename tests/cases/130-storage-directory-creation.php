<?php

require __DIR__.'/../test-helpers.php';

// Test that storage directory structure is created correctly

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

assert_directory_exists("$projectPath/storage");
assert_directory_exists("$projectPath/storage/app/public");
assert_directory_exists("$projectPath/storage/app/private");
assert_directory_exists("$projectPath/storage/framework/cache/data");
assert_directory_exists("$projectPath/storage/framework/sessions");
assert_directory_exists("$projectPath/storage/framework/views");
assert_directory_exists("$projectPath/storage/logs");

file_put_contents("$projectPath/hooks/before-release.sh", "\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

[$statusCode] = lit('deploy');

assert_same(0, $statusCode);

// Release should have symlink to storage
assert_symlink("$projectPath/releases/1/storage");
assert_string_contains(readlink("$projectPath/releases/1/storage"), 'storage');
