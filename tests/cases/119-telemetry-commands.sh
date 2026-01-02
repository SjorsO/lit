# Test telemetry enable/disable commands

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

cd "$project_path"

# Telemetry is disabled by default in test environment
assert_file_missing "$world_path/lit/data/telemetry-enabled" || exit 1

# Disable telemetry when already disabled should fail
set +e
output=$(lit disable-telemetry 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "Telemetry is already disabled" || exit 1

# Enable telemetry should succeed
set +e
output=$(lit enable-telemetry 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Enabled anonymous telemetry" || exit 1
assert_file_exists "$world_path/lit/data/telemetry-enabled" || exit 1

# Enable telemetry again should fail (already enabled)
set +e
output=$(lit enable-telemetry 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "Telemetry is already enabled" || exit 1

# Disable telemetry should succeed
set +e
output=$(lit disable-telemetry 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Completely disabled telemetry" || exit 1
assert_file_missing "$world_path/lit/data/telemetry-enabled" || exit 1
