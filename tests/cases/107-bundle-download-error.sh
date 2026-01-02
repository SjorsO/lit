# Test that if bundle download fails, it handles gracefully

lit init "https://example.com/nonexistent-bundle.tar.gz" > /dev/null

project_path="$world_path/case/nonexistent-bundle"

echo "APP_KEY=test" > "$project_path/.env"

# Set up on-failure hook to record that it was called
echo 'echo "$2" > "$1/on-failure-called"' > "$project_path/hooks/on-failure.sh"

cd "$project_path"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

# Deploy should fail
assert_same 1 "$status_code" || exit 1

# No release should be created
assert_file_missing "$project_path/releases/1" || exit 1

# Current symlink should not exist
assert_file_missing "$project_path/current" || exit 1

# Output should mention download failure
assert_string_contains "$output" "Failed to download bundle" || exit 1

# Log should contain the download failure
log_content=$(cat "$project_path/logs/lit.log")
assert_string_contains "$log_content" "Failed to download bundle" || exit 1

# on-failure hook should have been called with was_released=false
assert_file_exists "$project_path/on-failure-called" || exit 1
assert_file_content "$project_path/on-failure-called" "false" || exit 1
