<?php

require __DIR__.'/../test-helpers.php';

// Regression test from v1: replacing the "(pending:PID)" log placeholder must work
// when the log line or result contains special characters like "/" (branch names).

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");
file_put_contents("$projectPath/hooks/before-release.sh", "\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");

chdir($projectPath);

// Checkout a branch with a slash in the name (it does not exist, but the log
// line and its replacement both contain the slashed branch name)
[$statusCode, $output] = lit('checkout', 'feature/does-not-exist');

assert_same(1, $statusCode);

$output = normalize_output($output);

assert_same(<<<'EXPECTED'
Switching to branch "feature/does-not-exist"...
Branch "feature/does-not-exist" does not exist on remote
EXPECTED, $output);

// The placeholder should be replaced with the result, slashes intact
$logContent = file_get_contents("$projectPath/logs/lit.log");

assert_string_contains($logContent, 'lit checkout feature/does-not-exist → failed (branch does not exist) (in ');
assert_string_not_contains($logContent, '(pending:');

// Also verify "&" and "\" don't break the replacement
[$statusCode, $output] = lit('checkout', 'a&b\\c/d');

assert_same(1, $statusCode);

$logContent = file_get_contents("$projectPath/logs/lit.log");

assert_string_contains($logContent, 'lit checkout a&b\\c/d → failed (branch does not exist) (in ');
assert_string_not_contains($logContent, '(pending:');
