<?php

require __DIR__.'/../test-helpers.php';

// When "lit deploy" runs through "ssh server 'lit deploy'" and the connection dies,
// sshd sends no signal (there is no pty). Lit only notices the disconnect when a
// write to stdout fails with a broken pipe. That failed write aborts the script.
// The shutdown handler must then still kill the hook process tree and run all cleanup.

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

// The hook signals that it started, then keeps printing. Each print makes lit
// write to its own stdout, so lit hits the broken pipe quickly. The hook also
// starts a child process (like composer) that waits and leaves a survival marker.
$hookChildScript = 'sleep 5; touch "$1/hook-child-survived"';

file_put_contents(
    "$projectPath/hooks/before-release.sh",
    'touch "$1/hook-started"'."\n"
    ."bash -c '$hookChildScript' hook-child \"\$1\" &\n"
    .'for i in $(seq 1 300); do echo "tick $i"; sleep 0.1; done'."\n"
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
$disconnectedAt = hrtime(true);

fclose($pipes[1]);

// Wait for lit to notice the broken pipe and exit. On PHP < 8.3 the exit code is
// only valid on the proc_get_status() call that observes the process exit.
$waited = 0;
$processStatus = proc_get_status($process);

while ($processStatus['running'] && $waited < 30_000) {
    usleep(100_000);

    $waited += 100;

    $processStatus = proc_get_status($process);
}

assert_same(false, $processStatus['running']);

proc_close($process);

// A dead stdout aborts the deploy with 141 (128 + SIGPIPE)
assert_same(141, $processStatus['exitcode']);

// The abort was handled promptly, not after the hook finished all its ticks
if (hrtime(true) - $disconnectedAt > 15 * 1_000_000_000) {
    printf("Expected the deploy to abort promptly after the broken pipe\n");
    exit(1);
}

// Wait until well past the moment the hook child would have written its marker
while (hrtime(true) - $disconnectedAt < 6 * 1_000_000_000) {
    usleep(100_000);
}

// The hook child was killed before it could write anything
assert_file_missing("$projectPath/hook-child-survived");

// The cleanup messages still reached the output log
$outputLogContent = file_get_contents("$projectPath/logs/lit-output.log");

assert_string_contains($outputLogContent, 'Deleting new but unreleased release directory');

// The unreleased release was deleted
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

// No release was made
assert_file_missing("$projectPath/current");
