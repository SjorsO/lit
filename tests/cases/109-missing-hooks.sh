# Test that missing hooks print "Wanted to run" messages

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Remove all hooks
rm "$project_path/hooks/before-release.sh"
rm "$project_path/hooks/after-release.sh"
rm "$project_path/hooks/on-failure.sh"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

# Deploy should succeed
assert_same 0 "$status_code" || exit 1

# Output should mention missing hooks
assert_string_contains "$output" 'Wanted to run' || exit 1
assert_string_contains "$output" 'hooks/before-release.sh" but it does not exist' || exit 1
assert_string_contains "$output" 'hooks/after-release.sh" but it does not exist' || exit 1

# on-failure should NOT be mentioned (only runs on failure)
assert_string_not_contains "$output" 'hooks/on-failure.sh' || exit 1

# Release should still be created and released
assert_directory_exists "$project_path/releases/1" || exit 1
assert_symlink "$project_path/current" || exit 1
