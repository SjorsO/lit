# Test that only one lit command can run at a time

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Empty hooks so the deploy can succeed
echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Manually create the lock directory to simulate another command running
mkdir "$project_path/lit/lit-is-currently-running"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

# Command should fail
assert_same 1 "$status_code" || exit 1

# Output should mention another command is running
assert_string_contains "$output" "Another Lit command is currently running" || exit 1

# Output should show how to fix it
assert_string_contains "$output" "rmdir" || exit 1

# Log should contain the abort message
log_content=$(cat "$project_path/logs/lit.log")
assert_string_contains "$log_content" "Aborted because another Lit command is currently running" || exit 1

# Lock directory should still exist (we created it, lit didn't)
assert_directory_exists "$project_path/lit/lit-is-currently-running" || exit 1

# Clean up lock and verify lit works again
rmdir "$project_path/lit/lit-is-currently-running"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

# Now it should succeed
assert_same 0 "$status_code" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1
