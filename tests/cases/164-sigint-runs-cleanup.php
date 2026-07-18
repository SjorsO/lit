<?php

require __DIR__.'/../test-helpers.php';

// New in v2: aborting a deploy with Ctrl+C (SIGINT) or SIGTERM must still run
// all cleanup: delete the unreleased release, run the on-failure hook, release
// the lock, and finish the log placeholder.

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

// The before-release hook signals that it started, then hangs
file_put_contents("$projectPath/hooks/before-release.sh", 'touch "$1/hook-started"'."\nsleep 30\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");
file_put_contents("$projectPath/hooks/on-failure.sh", 'echo "$2" > "$1/on-failure-called"'."\n");

chdir($projectPath);

foreach ([2 => 130, 15 => 143] as $signal => $expectedStatusCode) {
    // Reset markers from the previous round
    if (file_exists("$projectPath/hook-started")) {
        unlink("$projectPath/hook-started");
    }

    if (file_exists("$projectPath/on-failure-called")) {
        unlink("$projectPath/on-failure-called");
    }

    // Start a deploy in the background
    $process = proc_open(lit_command(['deploy']), [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $projectPath, lit_environment());

    stream_set_blocking($pipes[1], false);

    // Wait for the hook to start (the clone takes a few seconds)
    $waited = 0;

    while (! file_exists("$projectPath/hook-started") && $waited < 60_000) {
        usleep(100_000);

        $waited += 100;
    }

    assert_file_exists("$projectPath/hook-started");

    // Interrupt the deploy
    $interruptedAt = hrtime(true);

    proc_terminate($process, $signal);

    // Wait for it to exit and collect the output. On PHP < 8.3 the exit code is only
    // valid on the proc_get_status() call that observes the process exit, so keep that result.
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

    // The exit code reflects the signal
    assert_same($expectedStatusCode, $processStatus['exitcode']);

    // The abort was handled immediately, not after the hook finished sleeping
    if (hrtime(true) - $interruptedAt > 15 * 1_000_000_000) {
        printf("Expected the deploy to abort immediately after the signal\n");
        exit(1);
    }

    // The unreleased release was deleted
    assert_string_contains($output, 'Deleting new but unreleased release directory');
    assert_string_contains($output, 'Finished with errors');
    assert_file_missing("$projectPath/releases/1");

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
}
