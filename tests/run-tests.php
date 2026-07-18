<?php

require __DIR__.'/test-helpers.php';

$caseFilter = '';
$maxConcurrentTests = (int) trim((string) shell_exec('getconf _NPROCESSORS_ONLN')) ?: 4;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--parallel=')) {
        $maxConcurrentTests = max(1, (int) substr($argument, strlen('--parallel=')));
    } else {
        $caseFilter = $argument;
    }
}

$queuedCaseFiles = glob(__DIR__."/cases/$caseFilter*.php");

if (! $queuedCaseFiles) {
    printf('No tests found matching "%s"%s', $caseFilter, "\n");

    exit(1);
}

$timer = timer();

shuffle($queuedCaseFiles);

$maxNameLength = max(array_map(fn ($caseFile) => strlen(basename($caseFile)), glob(__DIR__.'/cases/*.php')));

$runningCases = [];
$failures = [];

function setup_world(string $worldPath): void
{
    $litSourcePath = dirname(__DIR__);

    run_process(['rm', '-rf', $worldPath], __DIR__);

    mkdir("$worldPath/lit/data", 0777, true);
    mkdir("$worldPath/case", 0777, true);

    run_process(['cp', '-R', "$litSourcePath/scripts", "$worldPath/lit/scripts"], __DIR__);
    run_process(['cp', '-R', "$litSourcePath/stubs", "$worldPath/lit/stubs"], __DIR__);

    copy("$litSourcePath/lit.php", "$worldPath/lit/lit.php");
    copy("$litSourcePath/help.txt", "$worldPath/lit/help.txt");

    file_put_contents("$worldPath/lit/data/installation-id", "testing\n");
}

// If we're inside of a directory that has been deleted, then shell-init/getcwd
// errors happen. This should never happen, so check after every test.
function find_getcwd_error(string $worldPath): string
{
    foreach (glob("$worldPath/case/*/logs/lit-output.log") ?: [] as $logFile) {
        $logContent = file_get_contents($logFile);

        if (str_contains($logContent, 'shell-init') || str_contains($logContent, 'getcwd')) {
            return "Found shell-init or getcwd error in $logFile";
        }
    }

    return '';
}

while ($queuedCaseFiles || $runningCases) {
    while ($queuedCaseFiles && count($runningCases) < $maxConcurrentTests) {
        $caseFile = array_shift($queuedCaseFiles);
        $caseName = basename($caseFile);
        $caseNumber = explode('-', $caseName)[0];

        // Each case runs in its own world directory, reset before every run
        $worldPath = __DIR__."/worlds/world-$caseNumber";

        setup_world($worldPath);

        putenv("LIT_WORLD_PATH=$worldPath");

        $process = proc_open([PHP_BINARY, $caseFile], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, "$worldPath/case");

        stream_set_blocking($pipes[1], false);

        $runningCases[$caseName] = ['process' => $process, 'pipe' => $pipes[1], 'output' => '', 'worldPath' => $worldPath];
    }

    foreach (array_keys($runningCases) as $caseName) {
        // Keep draining the pipe so the case can't block on a full buffer
        $runningCases[$caseName]['output'] .= stream_get_contents($runningCases[$caseName]['pipe']);

        $processStatus = proc_get_status($runningCases[$caseName]['process']);

        if ($processStatus['running']) {
            continue;
        }

        $runningCases[$caseName]['output'] .= stream_get_contents($runningCases[$caseName]['pipe']);

        fclose($runningCases[$caseName]['pipe']);

        proc_close($runningCases[$caseName]['process']);

        $statusCode = $processStatus['exitcode'];
        $getcwdError = find_getcwd_error($runningCases[$caseName]['worldPath']);

        if ($statusCode === 0 && $getcwdError !== '') {
            $statusCode = 1;
            $runningCases[$caseName]['output'] .= $getcwdError;
        }

        printf("%-{$maxNameLength}s    ", $caseName);

        if ($statusCode === 0) {
            printf("✓\n");
        } else {
            printf("✗\n");

            $failures[$caseName] = rtrim($runningCases[$caseName]['output'], "\n");
        }

        unset($runningCases[$caseName]);
    }

    usleep(10_000);
}

if ($failures) {
    printf("\n");

    foreach ($failures as $caseName => $output) {
        printf("=== %s ===\n%s\n\n", $caseName, $output);
    }

    printf("%d test(s) failed (in %s)\n", count($failures), $timer->pretty_elapsed_time());

    exit(1);
}

printf("\nAll tests passed (in %s)\n", $timer->pretty_elapsed_time());
