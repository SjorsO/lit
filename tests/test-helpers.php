<?php

function is_macos(): bool
{
    return PHP_OS_FAMILY === 'Darwin';
}

function world_path(): string
{
    return getenv('LIT_WORLD_PATH');
}

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

function lit_with_input(string $stdinContent, array $extraEnvironment, string ...$arguments): array
{
    return run_process(lit_command($arguments), getcwd(), lit_environment($extraEnvironment), $stdinContent);
}

function lit_environment(array $extraEnvironment = []): array
{
    $environment = array_merge(getenv(), $extraEnvironment);

    // If "bin" exists, prepend it to the PATH. Tests use this to mock binaries like curl.
    $worldBinPath = world_path().'/bin';

    if (is_dir($worldBinPath)) {
        $environment['PATH'] = "$worldBinPath:{$environment['PATH']}";
    }

    return $environment;
}

function lit_state(string $projectPath): array
{
    return json_decode(file_get_contents("$projectPath/lit.json"), associative: true) ?: [];
}

function set_lit_state_value(string $projectPath, string $key, string|bool $value): void
{
    $litState = lit_state($projectPath);

    $litState[$key] = $value;

    file_put_contents("$projectPath/lit.json", json_encode($litState, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}

function assert_lit_state_value(string $projectPath, string $key, string|bool $expected): void
{
    if (! is_file("$projectPath/lit.json")) {
        fail_assertion("Expected file to exist: $projectPath/lit.json");
    }

    $actual = lit_state($projectPath)[$key] ?? null;

    if ($actual !== $expected) {
        fail_assertion(sprintf("lit.json key \"%s\":\nExpected: %s\n\nActual: %s", $key, var_export($expected, true), var_export($actual, true)));
    }
}

function assert_lit_state_missing(string $projectPath, string $key): void
{
    if (array_key_exists($key, lit_state($projectPath))) {
        fail_assertion("Expected lit.json to not have key \"$key\"");
    }
}

// Overwrite the release hooks so they do nothing
function neutralize_hooks(string $projectPath): void
{
    file_put_contents("$projectPath/hooks/before-release.sh", "\n");
    file_put_contents("$projectPath/hooks/after-release.sh", "\n");
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

function normalize_output(string $output, bool $preserveHashes = false): string
{
    // Strip ANSI escape codes and carriage returns (the install menu uses them)
    $output = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $output);
    $output = str_replace("\r", '', $output);

    // Deploys print recent commits, drop that block (hashes and messages change)
    // The block has an empty line before and after, drop those too
    $output = preg_replace('/^\n(?:(?:─▶ |┌▶ |│  |└─ |   )[a-f0-9]{7,40} .*\n)+\n/m', '', $output);

    $output = preg_replace('/\(in [0-9]+\.[0-9]+ seconds\)/', '(in X seconds)', $output);
    $output = preg_replace('/\(in [0-9]+\.[0-9]+s\)/', '(in X seconds)', $output);
    $output = preg_replace('/\([0-9]+K in [0-9]+\.[0-9]+ seconds\)/', '(XK in X seconds)', $output);
    $output = preg_replace('/\([a-f0-9]{11}\)/', '(COMMIT)', $output);

    if (! $preserveHashes) {
        $output = preg_replace('/[a-f0-9]{40}/', 'HASH', $output);
    }

    return preg_replace('/[ \t]+$/m', '', $output);
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

function assert_output_is_help_text(string $output): void
{
    assert_same(rtrim(file_get_contents(world_path().'/lit/help.txt'), "\n"), $output);
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
