<?php

require __DIR__.'/scripts/helpers.php';

if (! extension_loaded('pcntl') || ! extension_loaded('posix')) {
    echo "Lit requires the pcntl and posix PHP extensions\n";

    lit_exit(1);
}

$litBasePath = __DIR__;
$projectBasePath = getcwd();
$arguments = array_slice($argv, 1);
$command = $arguments[0] ?? '';

$GLOBALS['cleanup_stack'] = [];
$GLOBALS['lit_exit_code'] = 1;
$GLOBALS['lit_output_log_path'] = null;
$GLOBALS['current_child_process'] = null;
$GLOBALS['current_run_result'] = '';
$GLOBALS['is_terminating'] = false;

register_shutdown_function(function () {
    $GLOBALS['is_terminating'] = true;

    terminate_current_child_process_tree();

    foreach (array_reverse($GLOBALS['cleanup_stack']) as $closure) {
        $closure($GLOBALS['lit_exit_code']);
    }
});

pcntl_async_signals(true);

foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
    pcntl_signal($signal, function (int $signal) {
        if ($GLOBALS['is_terminating']) {
            return;
        }

        $GLOBALS['is_terminating'] = true;

        $GLOBALS['lit_exit_code'] = 128 + $signal;

        $signalName = match ($signal) {
            SIGINT => 'interrupt',
            SIGTERM => 'terminate signal',
            SIGHUP => 'hangup signal',
        };

        out("\n($signalName received)\n");

        terminate_current_child_process_tree();

        lit_exit(128 + $signal);
    }, restart_syscalls: false);
}

if (! file_exists("$litBasePath/data/installation-id")) {
    require "$litBasePath/scripts/install.php";
}

if ($command === 'init') {
    require "$litBasePath/scripts/init.php";

    lit_exit(0);
}

if ($command === 'help') {
    echo file_get_contents("$litBasePath/help.txt");

    lit_exit(0);
}

$hasLitV1StateFiles = file_exists("$projectBasePath/git-repository-url") || file_exists("$projectBasePath/bundle-url");

if (! file_exists("$projectBasePath/lit.json") && ! $hasLitV1StateFiles) {
    echo "This is not a Lit directory\n";

    lit_exit(1);
}

if (! is_dir("$projectBasePath/storage") && ! is_dir("$projectBasePath/shared/storage")) {
    echo "This looks like a Lit directory, but the storage directory does not exist\n";

    lit_exit(1);
}

// The releases directory might not exist when moving an application between servers
if (! is_dir("$projectBasePath/releases")) {
    mkdir("$projectBasePath/releases");
}

if (! is_dir("$projectBasePath/logs")) {
    mkdir("$projectBasePath/logs");
}

$lockDirectoryPath = "$projectBasePath/lit-is-currently-running";

// Allow running "lit flush-opcache" from inside a "lit deploy" without it logging to "lit.log"
if ($command === 'flush-opcache' && getenv('__lit_allow_flush_opcache_without_lock') === 'true') {
    require "$litBasePath/scripts/flush-opcache.php";

    lit_exit(0);
}

$startTime = current_time_in_ms();
$commandLine = rtrim('lit '.implode(' ', $arguments));
$pid = getmypid();

// Block signals until the cleanup below is registered
pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM, SIGHUP], $previousSignalMask);

acquire_lit_log_lock($projectBasePath);
file_put_contents("$projectBasePath/logs/lit.log", '['.get_human_timestamp()."] $commandLine (pending:$pid)\n", FILE_APPEND);
release_lit_log_lock($projectBasePath);

file_put_contents("$projectBasePath/logs/lit-output.log", '['.get_human_timestamp()."] $commandLine\n", FILE_APPEND);

// From this point on, everything written with out() also goes to lit-output.log
$GLOBALS['lit_output_log_path'] = "$projectBasePath/logs/lit-output.log";

// mkdir is atomic, it errors with "File exists" if the directory already exists. Ignore only that error.
set_error_handler(fn (int $errorNumber, string $errorMessage) => str_contains($errorMessage, 'File exists'));

// Ensure we can't run multiple commands at the same time.
$lockAcquired = mkdir($lockDirectoryPath);

restore_error_handler();

if (! $lockAcquired) {
    out("Another Lit command is currently running for this project, aborting...\n");
    out("If this is wrong, manually run:\n");
    out("    rmdir \"$lockDirectoryPath\"\n");

    replace_log_placeholder($projectBasePath, $pid, 'aborted, another lit command is currently running', current_time_in_ms() - $startTime);

    lit_exit(1);
}

register_cleanup(function () use ($projectBasePath, $lockDirectoryPath, $pid, $startTime) {
    replace_log_placeholder($projectBasePath, $pid, $GLOBALS['current_run_result'], current_time_in_ms() - $startTime);

    if (is_dir($lockDirectoryPath)) {
        rmdir($lockDirectoryPath);
    }
});

pcntl_sigprocmask(SIG_SETMASK, $previousSignalMask);

// Apps still on Lit v1 have separate state files instead of a "lit.json"
if (! file_exists("$projectBasePath/lit.json")) {
    require_once "$litBasePath/scripts/migrate-state-from-v1-to-v2.php";

    migrate_state_from_v1_to_v2($projectBasePath);
}

if ($command === 'deploy') {
    putenv('__lit_allow_flush_opcache_without_lock=true');

    require "$litBasePath/scripts/deploy.php";
} elseif ($command === 'checkout') {
    putenv('__lit_allow_flush_opcache_without_lock=true');

    require "$litBasePath/scripts/checkout.php";
} elseif ($command === 'redeploy') {
    putenv('__lit_allow_flush_opcache_without_lock=true');

    require "$litBasePath/scripts/redeploy.php";
} elseif ($command === 'enable-git-release-caching') {
    require "$litBasePath/scripts/enable-git-release-caching.php";
} elseif ($command === 'disable-git-release-caching') {
    require "$litBasePath/scripts/disable-git-release-caching.php";
} elseif ($command === 'flush-opcache') {
    require "$litBasePath/scripts/flush-opcache.php";
} elseif ($command === 'opcache-status') {
    require "$litBasePath/scripts/opcache-status.php";
} else {
    $GLOBALS['current_run_result'] = 'failed (unknown command)';

    out(file_get_contents("$litBasePath/help.txt"));

    lit_exit(1);
}

lit_exit(0);
