<?php

require __DIR__.'/../test-helpers.php';

// Mock curl via the world "bin" directory: instead of making an HTTP request, the mock
// executes the deployed lit-*.php script locally with a mocked opcache_get_status()

$worldPath = world_path();
$fixturePath = realpath(__DIR__.'/../fixtures/opcache-status-output.php');

mkdir("$worldPath/bin");

file_put_contents("$worldPath/bin/curl", str_replace('__FIXTURE__', $fixturePath, <<<'SHIM'
#!/bin/bash

url="$1"

wrapper=$(mktemp)

printf '<?php\nnamespace LitTest;\n\n' > "$wrapper"

if [[ "$url" == *"?json"* ]]; then
    printf '$_GET["json"] = "1";\n\n' >> "$wrapper"
fi

# The fixture defines a mocked opcache_get_status() in the LitTest namespace
tail -n +2 "__FIXTURE__" >> "$wrapper"

# Append the deployed PHP script without the opening <?php tag
script_file=$(ls current/public/lit-*.php 2>/dev/null | head -1)

tail -n +2 "$script_file" >> "$wrapper"

php "$wrapper"
php_exit_code=$?
rm -f "$wrapper"
exit $php_exit_code
SHIM."\n"));

chmod("$worldPath/bin/curl", 0755);

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = "$worldPath/case/lit";

chdir($projectPath);

file_put_contents("$projectPath/.env", "APP_URL=https://example.com/\n");

file_put_contents("$projectPath/hooks/before-release.sh", "# no-op\n");
file_put_contents("$projectPath/hooks/after-release.sh", 'mkdir -p "$2/public"'."\n");

[$statusCode] = lit('deploy');

assert_same(0, $statusCode);

// Test formatted output
[$statusCode, $output] = lit('opcache-status');

assert_same(0, $statusCode);

// Normalize the dynamic "Started" time-ago value
$output = preg_replace('/Started:                 .*/', 'Started:                 X ago', $output);

assert_same(<<<'EXPECTED'
Calling "https://example.com" to get OPcache status.
OPcache:
  Cache full:              No
  Restart pending:         No
  Restart in progress:     No
  Memory:                  102.0 MB (max: 512.0 MB, free: 410.0 MB)
  Wasted memory:           0.0 KB (0.0%)
  Interned strings:        64,091
  Interned strings memory: 20.1 MB (max: 64.0 MB, free: 43.9 MB)
  Cached scripts:          922
  Cached keys:             1,817 (max: 262,237, free: 260,420)
  Hits:                    48,343 (misses: 922, hit rate: 98.1%)
  Blacklist misses:        0 (ratio: 0.0%)
  OOM restarts:            0
  Hash restarts:           0
  Manual restarts:         0
  Started:                 X ago
  Last restart:            Never

JIT:
  Status:     Enabled (but not turned on)
  Kind:       0
  Opt level:  0
  Opt flags:  0
  Buffer:     64.0 MB (free: 64.0 MB)
EXPECTED, $output);

// Verify the temporary PHP file was cleaned up
assert_same(0, count(glob("$projectPath/current/public/lit-*.php")));

// Test --json output
[$statusCode, $output] = lit('opcache-status', '--json');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Calling "https://example.com" to get OPcache status.
{
    "opcache_enabled": true,
    "cache_full": false,
    "restart_pending": false,
    "restart_in_progress": false,
    "memory_usage": {
        "used_memory": 106993152,
        "free_memory": 429877760,
        "wasted_memory": 0,
        "current_wasted_percentage": 0
    },
    "interned_strings_usage": {
        "buffer_size": 67108864,
        "used_memory": 21033368,
        "free_memory": 46075496,
        "number_of_strings": 64091
    },
    "opcache_statistics": {
        "num_cached_scripts": 922,
        "num_cached_keys": 1817,
        "max_cached_keys": 262237,
        "hits": 48343,
        "start_time": 1769675952,
        "last_restart_time": 0,
        "oom_restarts": 0,
        "hash_restarts": 0,
        "manual_restarts": 0,
        "misses": 922,
        "blacklist_misses": 0,
        "blacklist_miss_ratio": 0,
        "opcache_hit_rate": 98.12848878514157
    },
    "jit": {
        "enabled": true,
        "on": false,
        "kind": 0,
        "opt_level": 0,
        "opt_flags": 0,
        "buffer_size": 67108848,
        "buffer_free": 67106363
    }
}
EXPECTED, $output);

// Verify the temporary PHP file was cleaned up
assert_same(0, count(glob("$projectPath/current/public/lit-*.php")));
