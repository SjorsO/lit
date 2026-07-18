<?php

require __DIR__.'/../test-helpers.php';

// Test that you can symlink {project}/logs to {project}/storage/logs to have all your logs in the same directory.

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

// Fill in .env
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

// Empty out the hooks (default hooks run composer install which fails for this repo)
file_put_contents("$projectPath/hooks/before-release.sh", "\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");

// Remove the logs directory created by init
run_process(['rm', '-rf', "$projectPath/logs"], $projectPath);

// Create storage/logs like Laravel has
if (! is_dir("$projectPath/storage/logs")) {
    mkdir("$projectPath/storage/logs", 0777, true);
}

// Symlink logs to storage/logs (so all logs end up in one place)
symlink("$projectPath/storage/logs", "$projectPath/logs");

chdir($projectPath);

[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

// Assert that logs is a symlink pointing to storage/logs
assert_symlink("$projectPath/logs");
assert_same("$projectPath/storage/logs", readlink("$projectPath/logs"));

// Assert that lit.log was created in storage/logs (via the symlink)
assert_file_exists("$projectPath/storage/logs/lit.log");
assert_file_exists("$projectPath/storage/logs/lit-output.log");

// Verify the log files are also accessible via the symlink
assert_file_exists("$projectPath/logs/lit.log");
assert_file_exists("$projectPath/logs/lit-output.log");

// Verify the log contains the deploy entry
$logContent = file_get_contents("$projectPath/logs/lit.log");

assert_string_contains($logContent, 'lit deploy');
assert_string_contains($logContent, 'deployed branch "main"');
