<?php

require __DIR__.'/../test-helpers.php';

// In v1 a deploy kept running after reading the remote failed.
// Now every command must stop right away when that happens.

$worldPath = world_path();
$caseDir = "$worldPath/case";

$seedPath = "$caseDir/seed";
$remotePath = "$caseDir/origin-repo.git";
$remoteUrl = "file://$remotePath";

$gitCommand = ['git', '-c', 'user.email=lit@test', '-c', 'user.name=lit'];

run_process(['git', 'init', '--quiet', '--initial-branch=main', $seedPath], $caseDir);

file_put_contents("$seedPath/app.txt", "one\n");
run_process(['git', 'add', 'app.txt'], $seedPath);
run_process([...$gitCommand, 'commit', '--quiet', '-m', 'one'], $seedPath);

run_process(['git', 'clone', '--quiet', '--bare', $seedPath, $remotePath], $caseDir);

chdir($caseDir);

[$statusCode] = lit('init', $remoteUrl);

assert_same(0, $statusCode);

$projectPath = "$caseDir/origin-repo";

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// The first deploy works fine
[$statusCode] = lit('deploy');

assert_same(0, $statusCode);
assert_directory_exists("$projectPath/releases/1");

// The remote disappears (deleted repo, or revoked access rights)
run_process(['rm', '-rf', $remotePath], $caseDir);

// Deploy must stop right away
[$statusCode, $output] = lit('deploy');

assert_same(128, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "$remoteUrl"...

fatal: '$remotePath' does not appear to be a git repository
fatal: Could not read from remote repository.

Please make sure you have the correct access rights
and the repository exists.

Reading the remote repository failed
Finished with errors (in X seconds)
EXPECTED, $output);

// No new release directory was created
assert_file_missing("$projectPath/releases/2");

// The current release is untouched
assert_string_contains(readlink("$projectPath/current"), 'releases/1');

// The lock was released
assert_file_missing("$projectPath/lit-is-currently-running");

// The log entry says why the deploy failed
$logContent = file_get_contents("$projectPath/logs/lit.log");

assert_string_contains($logContent, '→ failed (reading the remote repository failed) (in ');

// Checkout must also stop right away
[$statusCode, $output] = lit('checkout', 'other-branch');

assert_same(128, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "other-branch"...

fatal: '$remotePath' does not appear to be a git repository
fatal: Could not read from remote repository.

Please make sure you have the correct access rights
and the repository exists.

Reading the remote repository failed
EXPECTED, $output);

// A connection error must also stop the deploy right away.
// Port 1 is closed, git fails with "Connection refused".
set_lit_state_value($projectPath, 'git_repository_url', 'git://127.0.0.1:1/repo.git');

[$statusCode, $output] = lit('deploy');

assert_same(128, $statusCode);
assert_string_contains($output, 'Connection refused');
assert_string_contains($output, 'Reading the remote repository failed');
assert_string_contains($output, 'Finished with errors');

assert_file_missing("$projectPath/releases/2");
assert_file_missing("$projectPath/lit-is-currently-running");
