<?php

require __DIR__.'/../test-helpers.php';

// Test that log files contain expected content

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/hooks/before-release.sh", "\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// Deploy should create log files
[$statusCode] = lit('deploy');

assert_same(0, $statusCode);

// lit.log should contain deployment info in single-line format
$logContent = file_get_contents("$projectPath/logs/lit.log");

assert_string_contains($logContent, 'lit deploy → deployed branch');
assert_string_contains($logContent, 'main');
assert_string_contains($logContent, '(in ');

// lit-output.log should contain command and finished marker
$outputLogContent = file_get_contents("$projectPath/logs/lit-output.log");

assert_string_contains($outputLogContent, 'lit deploy');
assert_string_contains($outputLogContent, 'Finished');

// Deploy again with same commit - should log that it's already deployed
[$statusCode] = lit('deploy');

assert_same(0, $statusCode);

assert_string_contains(file_get_contents("$projectPath/logs/lit.log"), 'lit deploy → aborted, this commit is already deployed');
