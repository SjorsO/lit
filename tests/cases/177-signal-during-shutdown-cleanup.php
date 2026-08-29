<?php

require __DIR__.'/../test-helpers.php';

// A signal that arrives while the shutdown handler is already cleaning up must not
// abort that cleanup halfway: that would leave the lock and the release behind.
// The hook ignores SIGTERM, so the shutdown handler is stuck in its force-kill
// escalation for 10 seconds. That gives the test a wide window to hit lit with
// a signal mid-cleanup.

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

// The ticks force lit to write to its broken stdout
file_put_contents(
    "$projectPath/hooks/before-release.sh",
    'touch "$1/hook-started"'."\n"
    ."trap '' TERM\n"
    .'while true; do echo tick; sleep 0.2; done'."\n"
);

file_put_contents("$projectPath/hooks/after-release.sh", "\n");
file_put_contents("$projectPath/hooks/on-failure.sh", 'echo "$2" > "$1/on-failure-called"'."\n");

chdir($projectPath);

// Start a deploy in the background
$process = proc_open(lit_command(['deploy']), [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $projectPath, lit_environment());

stream_set_blocking($pipes[1], false);

// Wait for the hook to start (the clone takes a few seconds)
$waited = 0;

while (! file_exists("$projectPath/hook-started") && $waited < 60_000) {
    // Keep the pipe drained so lit can't block on a full buffer
    stream_get_contents($pipes[1]);

    usleep(100_000);

    $waited += 100;
}

assert_file_exists("$projectPath/hook-started");

// Simulate the SSH connection dying: close the read end of lit's stdout
fclose($pipes[1]);

// Give lit time to hit the broken pipe and get stuck killing the stubborn
// hook, then hit it with a signal in the middle of that cleanup
sleep(3);

proc_terminate($process, SIGTERM);

// Wait for lit to exit
$waited = 0;
$processStatus = proc_get_status($process);

while ($processStatus['running'] && $waited < 30_000) {
    usleep(100_000);

    $waited += 100;

    $processStatus = proc_get_status($process);
}

assert_same(false, $processStatus['running']);

proc_close($process);

// The signal did not abort the cleanup: the unreleased release was deleted
assert_same([], glob("$projectPath/releases/*"));

// The on-failure hook ran with was_released=false
assert_file_exists("$projectPath/on-failure-called");
assert_file_content("$projectPath/on-failure-called", 'false');

// The lock was released
assert_file_missing("$projectPath/lit-is-currently-running");

// The log placeholder was finished
$logContent = file_get_contents("$projectPath/logs/lit.log");

assert_string_contains($logContent, 'lit deploy → failed, deployment was not released (in ');
assert_string_not_contains($logContent, '(pending:');
