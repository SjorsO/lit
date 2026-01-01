# Test that if before-activation hook fails, the deploy fails and release is cleaned up

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Make before-activation hook fail
echo 'exit 1' > "$project_path/hooks/before-activation.sh"
echo '' > "$project_path/hooks/after-activation.sh"

# Set up on-failure hook to record that it was called
echo 'echo "$2" > "$1/on-failure-called"' > "$project_path/hooks/on-failure.sh"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# First deploy should fail
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_file_missing "$project_path/releases/1" || exit 1
assert_file_missing "$project_path/current" || exit 1
assert_string_contains "$output" "Deleting new but unactivated release" || exit 1

# on-failure hook should have been called with has_activated_release=false
assert_file_exists "$project_path/on-failure-called" || exit 1
assert_file_content "$project_path/on-failure-called" "false" || exit 1
rm "$project_path/on-failure-called"

# Fix the hook, second deploy should succeed
echo '' > "$project_path/hooks/before-activation.sh"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1
assert_symlink "$project_path/current" || exit 1
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/1" || exit 1

# on-failure hook should NOT have been called
assert_file_missing "$project_path/on-failure-called" || exit 1

# Break the hook again, third deploy should fail
echo 'exit 1' > "$project_path/hooks/before-activation.sh"

set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1

# Failed release should be cleaned up
assert_file_missing "$project_path/releases/2" || exit 1

# But release 1 should still exist
assert_directory_exists "$project_path/releases/1" || exit 1

# Current symlink should still point to release 1
assert_symlink "$project_path/current" || exit 1
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/1" || exit 1

# Output should mention the failed release was deleted
assert_string_contains "$output" "Deleting new but unactivated release" || exit 1

# on-failure hook should have been called with has_activated_release=false
assert_file_exists "$project_path/on-failure-called" || exit 1
assert_file_content "$project_path/on-failure-called" "false" || exit 1
