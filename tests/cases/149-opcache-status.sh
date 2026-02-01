# Override curl in opcache-status.sh to execute PHP locally with mocked opcache_get_status
fixture_content=$(tail -n +2 "$world_path/../../fixtures/opcache-status-output.php")
{
    cat << CURL_MOCK
curl() {
    local url="\$1"
    local wrapper
    wrapper=\$(mktemp)

    printf '<?php\nnamespace LitTest;\n\n' > "\$wrapper"

    if [[ "\$url" == *"?json"* ]]; then
        printf '\$_GET["json"] = "1";\n\n' >> "\$wrapper"
    fi

    cat << 'MOCK_FUNCTION' >> "\$wrapper"
${fixture_content}
MOCK_FUNCTION

    # Append the original PHP script without the opening <?php tag
    tail -n +2 "\$opcache_status_script_file_path" >> "\$wrapper"

    php "\$wrapper"
    local php_exit_code=\$?
    rm -f "\$wrapper"
    return \$php_exit_code
}
CURL_MOCK
    cat "$world_path/lit/scripts/opcache-status.sh"
} > "$world_path/lit/scripts/opcache-status.sh.tmp"
mv "$world_path/lit/scripts/opcache-status.sh.tmp" "$world_path/lit/scripts/opcache-status.sh"

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

cd "$project_path"

echo "APP_URL=https://example.com/" > "$project_path/.env"

cat << 'HOOK' > "$project_path/hooks/before-release.sh"
# no-op
HOOK

cat << 'HOOK' > "$project_path/hooks/after-release.sh"
mkdir -p "$2/public"
HOOK

lit deploy > /dev/null

# Test formatted output
set +e
output=$(lit opcache-status 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Normalize the dynamic "Started" time-ago value
output=$(echo "$output" | sed 's/Started:                 .*/Started:                 X ago/')

expected_output='Calling "https://example.com" to get OPcache status.
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
  Buffer:     64.0 MB (free: 64.0 MB)'
assert_exact_output "$expected_output" "$output" || exit 1

# Verify the temporary PHP file was cleaned up
php_files=$(find "$project_path/current/public" -name "lit-opcache-status-*.php" 2>/dev/null | wc -l | tr -d ' ')
assert_same "0" "$php_files" || exit 1

# Test --json output
set +e
output=$(lit opcache-status --json 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

expected_output='Calling "https://example.com" to get OPcache status.
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
}'
assert_exact_output "$expected_output" "$output" || exit 1

# Verify the temporary PHP file was cleaned up
php_files=$(find "$project_path/current/public" -name "lit-opcache-status-*.php" 2>/dev/null | wc -l | tr -d ' ')
assert_same "0" "$php_files" || exit 1
