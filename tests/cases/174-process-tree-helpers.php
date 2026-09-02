<?php

// Unit checks for the process tree helpers used when aborting a command.
// This file does not use test-helpers.php on purpose: it loads the real
// lit helpers, and both files define is_macos().

require getenv('LIT_WORLD_PATH').'/lit/scripts/helpers.php';

function check(bool $condition, string $message): void
{
    if (! $condition) {
        echo "Failed: $message\n";

        exit(1);
    }
}

// Polls until the process is gone, or 3 seconds pass
function wait_until_process_is_gone(int $pid): bool
{
    for ($attempts = 0; $attempts < 30; $attempts++) {
        if (! posix_kill($pid, 0)) {
            return true;
        }

        usleep(100_000);
    }

    return false;
}

// --- list_all_processes returns a good snapshot

$processes = list_all_processes();

check(is_array($processes), 'list_all_processes returns an array');
check(isset($processes[getmypid()]), 'the snapshot contains this process');
check($processes[getmypid()]['parent_pid'] === posix_getppid(), 'the snapshot has the correct parent pid');
check(($processes[getmypid()]['started_at'] ?? '') !== '', 'the snapshot has a start time');

// --- get_descendant_processes finds children of children

$process = proc_open(['bash', '-c', 'sleep 30 & sleep 31 & wait'], [], $pipes);

$bashPid = proc_get_status($process)['pid'];

// Give bash a moment to start the sleeps
usleep(500_000);

$descendants = get_descendant_processes($bashPid, list_all_processes());

check(count($descendants) >= 2, 'found the sleep processes below bash');

// --- a wrong start time blocks the signal, this protects against pid reuse

[$sleepPid] = array_keys($descendants);

send_signal_to_verified_process($sleepPid, 'a different start time', SIGKILL, list_all_processes());

usleep(200_000);

check(posix_kill($sleepPid, 0), 'a wrong start time must block the signal');

// --- the correct start time sends the signal

send_signal_to_verified_process($sleepPid, $descendants[$sleepPid], SIGKILL, list_all_processes());

check(wait_until_process_is_gone($sleepPid), 'the correct start time sends the signal');

// --- signaling ourselves is always refused, even with the correct start time

send_signal_to_verified_process(getmypid(), $processes[getmypid()]['started_at'], SIGKILL, list_all_processes());

// Still alive, otherwise this line would never run
check(true, 'never reached');

// --- clean up the leftover processes

proc_terminate($process, SIGKILL);

foreach ($descendants as $pid => $startedAt) {
    send_signal_to_verified_process($pid, $startedAt, SIGKILL, list_all_processes() ?? []);
}

proc_close($process);

// --- a pid loop in a broken "ps" snapshot must not hang the tree walk

$brokenSnapshot = [
    100 => ['parent_pid' => 300, 'started_at' => 'a'],
    200 => ['parent_pid' => 100, 'started_at' => 'b'],
    300 => ['parent_pid' => 200, 'started_at' => 'c'],
];

$loopDescendants = get_descendant_processes(100, $brokenSnapshot);

check(count($loopDescendants) === 2, 'a pid loop returns each descendant once');
check(isset($loopDescendants[200], $loopDescendants[300]), 'a pid loop still finds the descendants');
check(! isset($loopDescendants[100]), 'the walk never returns the starting pid itself');
