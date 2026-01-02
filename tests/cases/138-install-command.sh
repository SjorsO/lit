# Test the install command (non-interactive mode via piped input)

# Remove installation-id to trigger install on next lit command
rm "$world_path/lit/data/installation-id"

# Create a fake shell config file for alias testing
fake_rc="$world_path/.bashrc"
echo "# fake bashrc" > "$fake_rc"
HOME="$world_path"

cd "$world_path"

# Running "bash lit.sh" without installation-id should tell us to use "source" instead
set +e
output=$(bash "$world_path/lit/lit.sh" help 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" 'run "source lit.sh" instead' || exit 1

# Test 1: Install with "no" to both prompts (alias and telemetry)
# The yes_no_menu reads one char at a time with read -rsn1, so no newlines needed
# We use "source" in a subshell to run the install
set +e
output=$(printf 'nn' | bash -c "source '$world_path/lit/lit.sh'" 2>&1)
status_code=$?
set -e

# Should succeed (source returns 0 after install)
assert_same 0 "$status_code" || exit 1

# installation-id should be created
assert_file_exists "$world_path/lit/data/installation-id" || exit 1

# telemetry should NOT be enabled
assert_file_missing "$world_path/lit/data/telemetry-enabled" || exit 1

# Alias should NOT be added to bashrc
bashrc_content=$(cat "$fake_rc")
assert_string_not_contains "$bashrc_content" "lit.sh" || exit 1

# Test 2: Reset and test with "yes" to telemetry
rm "$world_path/lit/data/installation-id"
rm -f "$world_path/lit/data/telemetry-enabled"

set +e
output=$(printf 'ny' | bash -c "source '$world_path/lit/lit.sh'" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_file_exists "$world_path/lit/data/telemetry-enabled" || exit 1

# Test 3: Reset and test with "yes" to alias
rm "$world_path/lit/data/installation-id"
rm -f "$world_path/lit/data/telemetry-enabled"
echo "# fresh bashrc" > "$fake_rc"

set +e
output=$(printf 'yn' | bash -c "source '$world_path/lit/lit.sh'" 2>&1)
status_code=$?
set -e

# Source returns 0 after install (exit 100 from install.sh doesn't propagate through source)
assert_same 0 "$status_code" || exit 1

# Alias should be added to bashrc
bashrc_content=$(cat "$fake_rc")
assert_string_contains "$bashrc_content" "lit.sh" || exit 1
