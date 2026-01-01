# Test that if after-activation hook fails, the release is still activated but script exits with error

lit clone "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Make after-activation hook fail
echo '' > "$project_path/hooks/before-activation.sh"
echo 'exit 1' > "$project_path/hooks/after-activation.sh"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

set +e
output=$(lit pull 2>&1)
status_code=$?
set -e

# Deploy should fail (due to after-activation hook)
assert_same 1 "$status_code" || exit 1

# But release should still exist and be activated
assert_directory_exists "$project_path/releases/1" || exit 1
assert_symlink "$project_path/current" || exit 1

current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/1" || exit 1

# Output should warn that release was activated despite errors
assert_string_contains "$output" "Warning: The new release has been activated" || exit 1
