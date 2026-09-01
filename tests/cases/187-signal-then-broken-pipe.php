<?php

require __DIR__.'/../test-helpers.php';

// Ctrl+C in a wrapper script that pipes lit's output: lit still writes
// "(interrupt received)" to a live pipe, and the reader dies right after that.
// The first cleanup write then fails. That must not abandon the cleanup: PHP
// bails out fatally on a failed echo, which used to leave the lock behind.

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';
$litPath = world_path().'/lit';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");
file_put_contents("$projectPath/hooks/on-failure.sh", 'touch "$1/on-failure-ran"'."\n");

// The hook ignores SIGTERM, so lit is stuck in its force-kill escalation for
// 10 seconds. That is a wide window to kill the pipe reader in.
function stubborn_hook(string $markerPath): string
{
    return "touch \"$markerPath\"\n"
        ."trap '' TERM\n"
        ."while true; do sleep 0.2; done\n";
}

// Interrupts a deploy, then closes lit's stdout right after lit logged the interrupt
function interrupt_deploy_then_break_pipe(string $projectPath, string $markerPath): int
{
    $process = proc_open(lit_command(['deploy']), [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $projectPath, lit_environment());

    stream_set_blocking($pipes[1], false);

    // Wait for the hook to start (the clone takes a few seconds)
    for ($waited = 0; ! file_exists($markerPath) && $waited < 60_000; $waited += 100) {
        // Keep the pipe drained so lit can't block on a full buffer
        stream_get_contents($pipes[1]);

        usleep(100_000);
    }

    assert_file_exists($markerPath);

    // Ctrl+C
    proc_terminate($process, SIGINT);

    // Wait until lit logged the interrupt: proof the pipe was alive for that write
    for ($waited = 0; $waited < 10_000; $waited += 50) {
        stream_get_contents($pipes[1]);

        if (str_contains((string) @file_get_contents("$projectPath/logs/lit-output.log"), '(interrupt received)')) {
            break;
        }

        usleep(50_000);
    }

    // The wrapper script and the terminal are gone now
    fclose($pipes[1]);

    $processStatus = proc_get_status($process);

    for ($waited = 0; $processStatus['running'] && $waited < 40_000; $waited += 100) {
        usleep(100_000);

        $processStatus = proc_get_status($process);
    }

    assert_same(false, $processStatus['running']);

    proc_close($process);

    return $processStatus['exitcode'];
}

chdir($projectPath);

// Round one: interrupted during the before-release hook, so a release directory exists

file_put_contents("$projectPath/hooks/before-release.sh", stubborn_hook("$projectPath/hook-started"));

// The interrupt decides the exit code, not the dead pipe
assert_same(130, interrupt_deploy_then_break_pipe($projectPath, "$projectPath/hook-started"));

$outputLogContent = file_get_contents("$projectPath/logs/lit-output.log");

// The interrupt reached the log while the pipe was still alive
assert_string_contains($outputLogContent, '(interrupt received)');

// Everything after the dead pipe kept being logged
assert_string_contains($outputLogContent, '(stdout is gone, only writing to this log from here)');
assert_string_contains($outputLogContent, 'Deleting new but unreleased release directory');
assert_string_contains($outputLogContent, 'Finished with errors (in ');

// The unreleased release was deleted
assert_same([], glob("$projectPath/releases/*"));

// The on-failure hook still ran
assert_file_exists("$projectPath/on-failure-ran");

// The lock was released, a next deploy is not blocked
assert_file_missing("$projectPath/lit-is-currently-running");

// The log placeholder was finished
$logContent = file_get_contents("$projectPath/logs/lit.log");

assert_string_contains($logContent, 'lit deploy → failed, deployment was not released (in ');
assert_string_not_contains($logContent, '(pending:');

assert_file_missing("$projectPath/current");

// Round two: interrupted during the caching hook. Only a temporary clone exists,
// and cleanup deletes that one without printing anything first.

run_process(['bash', '-c', "rm -f '$projectPath'/logs/*.log '$projectPath'/hook-started '$projectPath'/on-failure-ran"], $projectPath);

file_put_contents("$projectPath/hooks/before-release.sh", "\n");

[$statusCode] = lit('enable-git-release-caching');

assert_same(0, $statusCode);

// $1 is the temporary clone, $2 is the project directory
file_put_contents("$projectPath/hooks/before-caching.sh", stubborn_hook('$2/caching-hook-started'));

assert_same(130, interrupt_deploy_then_break_pipe($projectPath, "$projectPath/caching-hook-started"));

$outputLogContent = file_get_contents("$projectPath/logs/lit-output.log");

assert_string_contains($outputLogContent, '(interrupt received)');
assert_string_contains($outputLogContent, '(stdout is gone, only writing to this log from here)');
assert_string_contains($outputLogContent, 'Finished with errors (in ');

// The temporary clone is gone, and so is the lock
assert_same([], glob("$litPath/cached-releases/wip_*"));
assert_file_missing("$projectPath/lit-is-currently-running");

// No release was made, and no half-built cache was left behind
assert_same([], glob("$projectPath/releases/*"));
assert_same([], glob("$litPath/cached-releases/*.building"));
assert_file_missing("$projectPath/current");

$logContent = file_get_contents("$projectPath/logs/lit.log");

assert_string_contains($logContent, 'lit deploy → failed (in ');
assert_string_not_contains($logContent, '(pending:');
