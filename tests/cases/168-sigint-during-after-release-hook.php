<?php

require __DIR__.'/../test-helpers.php';

// Aborting a deploy while the after-release hook runs is different from aborting earlier:
// the release was already released. The release must be kept, the on-failure hook must
// get was_released=true, and the log must say the deployment was still released.

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

file_put_contents("$projectPath/hooks/before-release.sh", "\n");

// The after-release hook signals that it started, then hangs
file_put_contents("$projectPath/hooks/after-release.sh", 'touch "$1/hook-started"'."\nsleep 30\n");

file_put_contents("$projectPath/hooks/on-failure.sh", 'echo "$2" > "$1/on-failure-called"'."\n");

chdir($projectPath);

$releaseId = 0;

foreach ([2 => 130, 15 => 143] as $signal => $expectedStatusCode) {
    $releaseId++;

    // Reset markers from the previous round
    if (file_exists("$projectPath/hook-started")) {
        unlink("$projectPath/hook-started");
    }

    if (file_exists("$projectPath/on-failure-called")) {
        unlink("$projectPath/on-failure-called");
    }

    // Start a deploy in the background. The second round deploys the same
    // commit again, "--force" skips the already-deployed abort.
    $process = proc_open(lit_command(['deploy', '--force']), [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $projectPath, lit_environment());

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

    // The signal notice was printed on its own line
    $expectedNotice = $signal === 2 ? '(interrupt received)' : '(terminate signal received)';

    assert_string_contains($output, "\n$expectedNotice\n");

    // The release was already released, it must be kept
    assert_string_not_contains($output, 'Deleting new but unreleased release directory');
    assert_string_contains($output, 'Warning: The new deployment was still released!');
    assert_string_contains($output, 'Finished with errors');

    assert_directory_exists("$projectPath/releases/$releaseId");
    assert_symlink("$projectPath/current");
    assert_string_contains(readlink("$projectPath/current"), "releases/$releaseId");

    // The on-failure hook ran with was_released=true
    assert_file_exists("$projectPath/on-failure-called");
    assert_file_content("$projectPath/on-failure-called", 'true');

    // The lock was released
    assert_file_missing("$projectPath/lit-is-currently-running");

    // The log placeholder was finished and says the deployment was released
    $logContent = file_get_contents("$projectPath/logs/lit.log");

    assert_string_contains($logContent, '→ had errors, still deployed branch "main" (commit: ');
    assert_string_not_contains($logContent, '(pending:');
}
