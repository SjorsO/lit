<?php

require __DIR__.'/../test-helpers.php';

// Aborting a deploy must also stop processes started by the hook (like composer),
// not just the hook itself. And cleanup must wait for them to exit. An orphaned
// process could otherwise recreate the release directory after cleanup deleted it.

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

// The hook starts a child process (like composer). The child waits,
// then writes into the release directory, and leaves a survival marker.
$hookChildScript = 'sleep 2; mkdir -p "$2/vendor"; touch "$2/vendor/written-too-late"; touch "$1/hook-child-survived"';

file_put_contents(
    "$projectPath/hooks/before-release.sh",
    'touch "$1/hook-started"'."\n"
    ."bash -c '$hookChildScript' hook-child \"\$1\" \"\$2\"\n"
);

file_put_contents("$projectPath/hooks/after-release.sh", "\n");
file_put_contents("$projectPath/hooks/on-failure.sh", "\n");

chdir($projectPath);

foreach ([2 => 130, 15 => 143] as $signal => $expectedStatusCode) {
    // Reset markers from the previous round
    if (file_exists("$projectPath/hook-started")) {
        unlink("$projectPath/hook-started");
    }

    if (file_exists("$projectPath/hook-child-survived")) {
        unlink("$projectPath/hook-child-survived");
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

    // Interrupt the deploy while the hook child is still sleeping
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

    // The signal notice was printed on its own line
    $expectedNotice = $signal === 2 ? '(interrupt received)' : '(terminate signal received)';

    assert_string_contains($output, "\n$expectedNotice\n");

    assert_string_contains($output, 'Deleting new but unreleased release directory');

    // Wait until well past the moment the hook child would have written its files
    while (hrtime(true) - $interruptedAt < 3 * 1_000_000_000) {
        usleep(100_000);
    }

    // The hook child was killed before it could write anything
    assert_file_missing("$projectPath/hook-child-survived");

    // The release directory stayed deleted, no orphaned process recreated it
    assert_same([], glob("$projectPath/releases/*"));

    // The lock was released
    assert_file_missing("$projectPath/lit-is-currently-running");
}
