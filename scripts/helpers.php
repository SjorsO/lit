<?php

function get_source_type(string $projectBasePath): string
{
    $litState = read_lit_state($projectBasePath);

    if (($litState['git_repository_url'] ?? '') !== '') {
        return 'git';
    }

    if (($litState['bundle_url'] ?? '') !== '') {
        return 'bundle';
    }

    return '';
}

function read_lit_state(string $projectBasePath): array
{
    if (! file_exists("$projectBasePath/lit.json")) {
        return [];
    }

    $litState = json_decode(file_get_contents("$projectBasePath/lit.json"), associative: true);

    return is_array($litState) ? $litState : [];
}

function write_lit_state(string $projectBasePath, array $litState): void
{
    file_put_contents("$projectBasePath/lit.json", json_encode($litState, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

function update_lit_state(string $projectBasePath, string $key, string|bool $value): void
{
    $litState = read_lit_state($projectBasePath);

    $litState[$key] = $value;

    write_lit_state($projectBasePath, $litState);
}

function resolve_remote_ref(string $gitRepositoryUrl, string $ref): array
{
    [$lsRemoteStatusCode, $lsRemoteOutput] = run_command_and_capture_stdout(['git', 'ls-remote', $gitRepositoryUrl, "refs/heads/$ref", "refs/tags/$ref*"]);

    if ($lsRemoteStatusCode !== 0) {
        out("Reading the remote repository failed\n");

        $GLOBALS['current_run_result'] = 'failed (reading the remote repository failed)';

        lit_exit($lsRemoteStatusCode);
    }

    $branchCommit = '';
    $tagCommit = '';
    $peeledTagCommit = '';

    foreach (explode("\n", $lsRemoteOutput) as $lsRemoteLine) {
        if (! str_contains($lsRemoteLine, "\t")) {
            continue;
        }

        [$commit, $refName] = explode("\t", $lsRemoteLine);

        if ($refName === "refs/heads/$ref") {
            $branchCommit = $commit;
        } elseif ($refName === "refs/tags/$ref") {
            $tagCommit = $commit;
        } elseif ($refName === "refs/tags/$ref^{}") {
            $peeledTagCommit = $commit;
        }
    }

    if ($peeledTagCommit !== '') {
        $tagCommit = $peeledTagCommit;
    }

    return [$branchCommit, $tagCommit];
}

function clone_git_ref_into(string $gitRepositoryUrl, string $ref, string $refType, string $directoryPath): int
{
    if ($refType === 'branch') {
        return run_command(['git', 'clone', '--branch', $ref, '--depth', '100', '--single-branch', '--quiet', $gitRepositoryUrl, $directoryPath]);
    }

    $fetchRef = $refType === 'tag' ? "refs/tags/$ref" : $ref;

    $initStatusCode = run_command(['git', 'init', '--quiet', $directoryPath]);

    if ($initStatusCode !== 0) {
        return $initStatusCode;
    }

    $fetchStatusCode = run_command(['git', '-C', $directoryPath, 'fetch', '--quiet', '--depth', '100', $gitRepositoryUrl, $fetchRef]);

    if ($fetchStatusCode !== 0) {
        return $fetchStatusCode;
    }

    return run_command(['git', '-C', $directoryPath, 'checkout', '--quiet', '--detach', 'FETCH_HEAD']);
}

function print_recent_commits(string $repositoryPath): void
{
    [$logStatusCode, $logOutput] = run_command_and_capture(['git', '-C', $repositoryPath, '-c', 'core.abbrev=7', 'log', '--oneline', '--no-decorate', '-5']);

    $logOutput = trim($logOutput);

    // Skip silently, a caching hook may have removed the ".git" directory
    if ($logStatusCode !== 0 || $logOutput === '') {
        return;
    }

    foreach (explode("\n", $logOutput) as $index => $logLine) {
        out(($index === 0 ? '> ' : '  ').$logLine."\n");
    }
}

function expand_short_commit(string $gitRepositoryUrl, string $litBasePath, string $shortCommit): array
{
    if (! is_dir("$litBasePath/cached-releases")) {
        mkdir("$litBasePath/cached-releases", 0777, true);
    }

    $clonePath = "$litBasePath/cached-releases/wip_".uuid();

    register_cleanup(function () use ($clonePath) {
        if (is_dir($clonePath)) {
            delete_directory($clonePath);
        }
    });

    // "--filter=tree:0" skips downloading file contents
    [$cloneStatusCode, $cloneOutput] = run_command_and_capture(['git', 'clone', '--quiet', '--bare', '--filter=tree:0', $gitRepositoryUrl, $clonePath]);

    if ($cloneStatusCode !== 0) {
        out("\n");
        out(trim($cloneOutput)."\n");

        lit_exit($cloneStatusCode);
    }

    // The "^{commit}" makes sure the hash is a commit, and peels tag objects
    [$revParseStatusCode, $revParseOutput] = run_command_and_capture(['git', '-C', $clonePath, 'rev-parse', '--verify', "$shortCommit^{commit}"]);

    delete_directory($clonePath);

    if ($revParseStatusCode !== 0) {
        return [
            str_contains($revParseOutput, 'ambiguous') ? 'ambiguous' : 'not-found',
            '',
        ];
    }

    return [
        'found',
        trim($revParseOutput),
    ];
}

function get_file_value(string $filePath): string
{
    return trim(file_get_contents($filePath));
}

function current_time_in_ms(): int
{
    return intdiv(hrtime(true), 1_000_000);
}

function get_human_timestamp(): string
{
    return date('Y-m-d H:i:s');
}

function is_macos(): bool
{
    return PHP_OS_FAMILY === 'Darwin';
}

function pretty_runtime(int $runtimeInMs): string
{
    return sprintf('%d.%02ds', intdiv($runtimeInMs, 1000), intdiv($runtimeInMs % 1000, 10));
}

function uuid(): string
{
    $bytes = random_bytes(16);

    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    $hex = bin2hex($bytes);

    return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
}

// The size format of "ls -lah": no decimal above 10, one decimal below 10
function human_file_size(int $bytes): string
{
    $units = ['B', 'K', 'M', 'G', 'T'];
    $size = (float) $bytes;
    $unitIndex = 0;

    while ($size >= 1024 && $unitIndex < count($units) - 1) {
        $size /= 1024;
        $unitIndex++;
    }

    if ($unitIndex === 0) {
        return $size.'B';
    }

    if ($size >= 10) {
        return ceil($size).$units[$unitIndex];
    }

    return sprintf('%.1f%s', ceil($size * 10) / 10, $units[$unitIndex]);
}

// Write to stdout, and to the lit-output.log once logging has started
function out(string $text): void
{
    echo $text;

    if ($GLOBALS['lit_output_log_path'] ?? null) {
        file_put_contents($GLOBALS['lit_output_log_path'], $text, FILE_APPEND);
    }
}

function lit_exit(int $statusCode): void
{
    $GLOBALS['lit_exit_code'] = $statusCode;

    exit($statusCode);
}

function register_cleanup(callable $cleanup): void
{
    $GLOBALS['cleanup_stack'][] = $cleanup;
}

function delete_directory(string $directoryPath): void
{
    run_command_and_capture(['rm', '-rf', $directoryPath]);
}

// Deletes a file if it exists, the is_link check covers symlinks
// with a missing target (file_exists returns false for those)
function delete_file(string $filePath): void
{
    if (file_exists($filePath) || is_link($filePath)) {
        unlink($filePath);
    }
}

// Waits for readable data without blocking signal delivery: a blocking fread would
// be restarted by PHP after a signal, so Ctrl+C could not abort while a hook runs.
// A signal interrupting the select causes an expected "Interrupted system call"
// warning, silence only that warning and let the signal handler take over
function wait_for_readable_pipes(array $pipes): void
{
    $read = $pipes;
    $write = null;
    $except = null;

    set_error_handler(fn (int $errorNumber, string $errorMessage) => str_contains($errorMessage, 'Interrupted system call'));

    stream_select($read, $write, $except, 0, 200_000);

    restore_error_handler();
}

// Streams stdout and stderr of the command through out(), returns the status code
function run_command(array $command, ?string $stdinContent = null, ?string $currentDirectory = null): int
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];

    if ($stdinContent !== null) {
        $descriptors[0] = ['pipe', 'r'];
    }

    $process = proc_open($command, $descriptors, $pipes, $currentDirectory);

    $GLOBALS['current_child_process'] = $process;

    if ($stdinContent !== null) {
        fwrite($pipes[0], $stdinContent);
        fclose($pipes[0]);
    }

    stream_set_blocking($pipes[1], false);

    while (! feof($pipes[1])) {
        wait_for_readable_pipes([$pipes[1]]);

        out(fread($pipes[1], 8192) ?: '');
    }

    fclose($pipes[1]);

    $GLOBALS['current_child_process'] = null;

    return proc_close($process);
}

// Returns [statusCode, output], with stderr merged into stdout, nothing is streamed
function run_command_and_capture(array $command, ?string $currentDirectory = null): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $currentDirectory);

    $GLOBALS['current_child_process'] = $process;

    stream_set_blocking($pipes[1], false);

    $output = '';

    while (! feof($pipes[1])) {
        wait_for_readable_pipes([$pipes[1]]);

        $output .= fread($pipes[1], 8192) ?: '';
    }

    fclose($pipes[1]);

    $GLOBALS['current_child_process'] = null;

    return [proc_close($process), $output];
}

// Returns [statusCode, stdout], stderr is streamed through out()
function run_command_and_capture_stdout(array $command): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    $GLOBALS['current_child_process'] = $process;

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';

    while (! feof($pipes[1]) || ! feof($pipes[2])) {
        $readablePipes = array_filter([$pipes[1], $pipes[2]], fn ($pipe) => ! feof($pipe));

        if (! $readablePipes) {
            break;
        }

        wait_for_readable_pipes($readablePipes);

        $stdout .= stream_get_contents($pipes[1]) ?: '';

        out(stream_get_contents($pipes[2]) ?: '');
    }

    fclose($pipes[1]);
    fclose($pipes[2]);

    $GLOBALS['current_child_process'] = null;

    return [proc_close($process), $stdout];
}

// Hooks are run by piping them into bash, this has been proven to work reliably.
// The -e ensures hooks fail properly. Symlinked hooks work because
// file_get_contents follows symlinks.
function run_hook(string $hookPath, array $hookArguments): int
{
    return run_command(['bash', '-se', '--', ...$hookArguments], file_get_contents($hookPath));
}

function acquire_lit_log_lock(string $projectBasePath): void
{
    // The mkdir warns with "File exists" when another lit process holds the lock,
    // that is the expected contention case, silence only that warning
    set_error_handler(fn (int $errorNumber, string $errorMessage) => str_contains($errorMessage, 'File exists'));

    for ($attempts = 0; $attempts < 10; $attempts++) {
        if (mkdir("$projectBasePath/lit-log-lock")) {
            restore_error_handler();

            return;
        }

        usleep(100_000);
    }

    restore_error_handler();
}

function release_lit_log_lock(string $projectBasePath): void
{
    if (is_dir("$projectBasePath/lit-log-lock")) {
        rmdir("$projectBasePath/lit-log-lock");
    }
}

function replace_log_placeholder(string $projectBasePath, int $pid, string $result, int $runtimeInMs): void
{
    $logFilePath = "$projectBasePath/logs/lit.log";

    acquire_lit_log_lock($projectBasePath);

    $lines = explode("\n", file_get_contents($logFilePath));

    foreach ($lines as $index => $line) {
        if (! str_ends_with($line, " (pending:$pid)")) {
            continue;
        }

        $linePrefix = substr($line, 0, -strlen(" (pending:$pid)"));

        if ($result === '') {
            $lines[$index] = $linePrefix;
        } else {
            $prettyRuntime = pretty_runtime($runtimeInMs);

            $lines[$index] = "$linePrefix → $result (in $prettyRuntime)";
        }
    }

    file_put_contents($logFilePath, implode("\n", $lines));

    release_lit_log_lock($projectBasePath);
}
