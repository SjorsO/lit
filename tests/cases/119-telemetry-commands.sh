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
assert_exact_output 'Telemetry is already disabled' "$output" || exit 1

# Enable telemetry should succeed
set +e
output=$(lit enable-telemetry 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_exact_output 'Enabled anonymous telemetry' "$output" || exit 1
assert_file_exists "$world_path/lit/data/telemetry-enabled" || exit 1

# Enable telemetry again should fail (already enabled)
set +e
output=$(lit enable-telemetry 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'Telemetry is already enabled' "$output" || exit 1

# Disable telemetry should succeed
set +e
output=$(lit disable-telemetry 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
expected_output='Completely disabled telemetry
No telemetry will be sent for any Lit project'
assert_exact_output "$expected_output" "$output" || exit 1
assert_file_missing "$world_path/lit/data/telemetry-enabled" || exit 1
