<?php

require __DIR__.'/../test-helpers.php';

// Cleanup must never delete the release "current" points at. If a deploy dies after the
// cutover but before it recorded the release, deleting it would take the site down.
// Test 164 covers the other side: a release that never went live is still deleted.

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

// The hook makes the new release live behind Lit's back, then hangs. That is the
// state a deploy is in between the cutover and marking the release as released.
file_put_contents("$projectPath/hooks/before-release.sh", 'ln -nsf "releases/$(basename "$2")" "$1/current"'."\n".'touch "$1/hook-started"'."\nsleep 30\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");
file_put_contents("$projectPath/hooks/on-failure.sh", 'echo "$2" > "$1/on-failure-called"'."\n");

chdir($projectPath);

$process = proc_open(lit_command(['deploy']), [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $projectPath, lit_environment());

stream_set_blocking($pipes[1], false);

// Wait for the hook to make the release live (the clone takes a few seconds)
$waited = 0;

while (! file_exists("$projectPath/hook-started") && $waited < 60_000) {
    usleep(100_000);

    $waited += 100;
}

assert_file_exists("$projectPath/hook-started");
assert_string_contains(readlink("$projectPath/current"), 'releases/1');

proc_terminate($process, 2);

$output = '';
$waited = 0;
$processStatus = proc_get_status($process);

while ($processStatus['running'] && $waited < 30_000) {
    $output .= stream_get_contents($pipes[1]);

    usleep(100_000);

    $waited += 100;

    $processStatus = proc_get_status($process);
}

assert_same(false, $processStatus['running']);

$output .= stream_get_contents($pipes[1]);

fclose($pipes[1]);
proc_close($process);

assert_same(130, $processStatus['exitcode']);

// The live release survived the abort
assert_string_contains($output, 'The new release is already live, keeping');
assert_string_not_contains($output, 'Deleting new but unreleased release directory');

assert_directory_exists("$projectPath/releases/1");

// "current" still resolves, the site is up
clearstatcache();

assert_same(true, file_exists("$projectPath/current"));
assert_string_contains(readlink("$projectPath/current"), 'releases/1');

// The abort is reported as a release that went live, not as a failed deploy
assert_string_contains($output, 'Warning: The new deployment was still released!');

assert_file_exists("$projectPath/on-failure-called");
assert_file_content("$projectPath/on-failure-called", 'true');

assert_string_contains(file_get_contents("$projectPath/logs/lit.log"), 'had errors, still deployed');

// No temp symlink was left behind in the project directory
assert_same([], glob("$projectPath/current-*"));

// The lock was released
assert_file_missing("$projectPath/lit-is-currently-running");
