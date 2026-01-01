# Test that missing hooks print "Wanted to run" messages

lit clone "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Remove all hooks
rm "$project_path/hooks/before-activation.sh"
rm "$project_path/hooks/after-activation.sh"
rm "$project_path/hooks/on-failure.sh"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

set +e
output=$(lit pull 2>&1)
status_code=$?
set -e

# Deploy should succeed
assert_same 0 "$status_code" || exit 1

# Output should mention missing hooks
assert_string_contains "$output" 'Wanted to run' || exit 1
assert_string_contains "$output" 'hooks/before-activation.sh" but it does not exist' || exit 1
assert_string_contains "$output" 'hooks/after-activation.sh" but it does not exist' || exit 1

# on-failure should NOT be mentioned (only runs on failure)
assert_string_not_contains "$output" 'hooks/on-failure.sh' || exit 1

# Release should still be created and activated
assert_directory_exists "$project_path/releases/1" || exit 1
assert_symlink "$project_path/current" || exit 1
