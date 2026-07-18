<?php

function world_path(): string
{
    return getenv('LIT_WORLD_PATH');
}

// Black box entry point: run the Lit implementation as a separate process.
// To test a different implementation (Rust, Go, ...), only change this command.
function lit_command(array $arguments): array
{
    return [PHP_BINARY, world_path().'/lit/lit.php', ...$arguments];
}

function lit(string ...$arguments): array
{
    return run_process(lit_command($arguments), getcwd(), lit_environment());
}

function lit_with_environment(array $extraEnvironment, string ...$arguments): array
{
    return run_process(lit_command($arguments), getcwd(), lit_environment($extraEnvironment));
}

// If the world has a "bin" directory, it is prepended to the PATH. Tests use
// this to mock binaries like curl.
function lit_environment(array $extraEnvironment = []): array
{
    $environment = array_merge(getenv(), $extraEnvironment);

    $worldBinPath = world_path().'/bin';

    if (is_dir($worldBinPath)) {
        $environment['PATH'] = "$worldBinPath:{$environment['PATH']}";
    }

    return $environment;
}

function timer(): object
{
    return new class
    {
        private int $startedAt;

        public function __construct()
        {
            $this->startedAt = hrtime(true);
        }

        public function pretty_elapsed_time(): string
        {
            $microseconds = (int) round((hrtime(true) - $this->startedAt) / 1_000);

            if ($microseconds >= 1_000_000) {
                return round($microseconds / 1_000_000, precision: 1).'s';
            }

            if ($microseconds >= 1000) {
                return ((int) round($microseconds / 1000)).'ms';
            }

            return "{$microseconds}μs";
        }
    };
}

// Returns [statusCode, output], with stderr merged into stdout
function run_process(array $command, string $currentDirectory, ?array $environment = null, ?string $stdinContent = null): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];

    if ($stdinContent !== null) {
        $descriptors[0] = ['pipe', 'r'];
    }

    $process = proc_open($command, $descriptors, $pipes, $currentDirectory, $environment);

    if ($stdinContent !== null) {
        fwrite($pipes[0], $stdinContent);
        fclose($pipes[0]);
    }

    $output = stream_get_contents($pipes[1]);

    fclose($pipes[1]);

    return [proc_close($process), rtrim($output, "\n")];
}

function lit_with_input(string $stdinContent, array $extraEnvironment, string ...$arguments): array
{
    return run_process(lit_command($arguments), getcwd(), lit_environment($extraEnvironment), $stdinContent);
}

// Replaces timings with placeholders and strips trailing whitespace from every line
function normalize_output(string $output): string
{
    $output = preg_replace('/\(in [0-9]+\.[0-9]+ seconds\)/', '(in X seconds)', $output);
    $output = preg_replace('/\(in [0-9]+\.[0-9]+s\)/', '(in X seconds)', $output);
    $output = preg_replace('/\([0-9]+K in [0-9]+\.[0-9]+ seconds\)/', '(XK in X seconds)', $output);

    return preg_replace('/[ \t]+$/m', '', $output);
}

function replace_hashes(string $output): string
{
    return preg_replace('/[a-f0-9]{40}/', 'HASH', $output);
}

function replace_commits(string $output): string
{
    return preg_replace('/\([a-f0-9]{11}\)/', '(COMMIT)', $output);
}

function replace_curl_errors(string $output): string
{
    return preg_replace('/curl: \([0-9]+\) .*/', 'curl: (CURL_ERROR)', $output);
}

function assert_same($expected, $actual): void
{
    if ($actual !== $expected) {
        fail_assertion(sprintf("Expected:\n%s\n\nActual:\n%s", var_export($expected, true), var_export($actual, true)));
    }
}

function assert_matches(string $pattern, string $subject): void
{
    if (! preg_match($pattern, $subject)) {
        fail_assertion(sprintf("Expected to match \"%s\":\n%s", $pattern, $subject));
    }
}

function assert_file_exists(string $filePath): void
{
    if (! is_file($filePath)) {
        fail_assertion("Expected file to exist: $filePath");
    }
}

function assert_directory_exists(string $directoryPath): void
{
    if (! is_dir($directoryPath)) {
        fail_assertion("Expected directory to exist: $directoryPath");
    }
}

// Compares like bash "$(cat file)": trailing newlines are ignored
function assert_file_content(string $filePath, string $expected): void
{
    if (! is_file($filePath)) {
        fail_assertion("Expected file to exist: $filePath");
    }

    $actual = rtrim(file_get_contents($filePath), "\n");

    if ($actual !== $expected) {
        fail_assertion("File $filePath:\nExpected:\n$expected\n\nActual:\n$actual");
    }
}

function assert_files_match(string $filePath1, string $filePath2): void
{
    if (! is_file($filePath1) || ! is_file($filePath2) || file_get_contents($filePath1) !== file_get_contents($filePath2)) {
        fail_assertion("Files do not match:\n  $filePath1\n  $filePath2");
    }
}

function assert_file_missing(string $filePath): void
{
    if (file_exists($filePath) || is_link($filePath)) {
        fail_assertion("Expected file to not exist: $filePath");
    }
}

function assert_symlink(string $filePath): void
{
    if (! is_link($filePath)) {
        fail_assertion("Expected symlink: $filePath");
    }
}

function assert_string_contains(string $haystack, string $needle): void
{
    if (! str_contains($haystack, $needle)) {
        fail_assertion("Expected string to contain \"$needle\", got:\n$haystack");
    }
}

function assert_string_not_contains(string $haystack, string $needle): void
{
    if (str_contains($haystack, $needle)) {
        fail_assertion("Expected string to NOT contain \"$needle\", got:\n$haystack");
    }
}

function is_macos(): bool
{
    return PHP_OS_FAMILY === 'Darwin';
}

function fail_assertion(string $message): void
{
    $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[1];

    if (($caller['file'] ?? '') === __FILE__) {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2];
    }

    printf('[%s:%d] %s%s', basename($caller['file']), $caller['line'], $message, "\n");

    exit(1);
}
