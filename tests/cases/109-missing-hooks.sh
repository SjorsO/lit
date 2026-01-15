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

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "'"$project_path"'/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Wanted to run "'"$project_path"'/hooks/before-release.sh" but it does not exist
Releasing the new deployment "'"$project_path"'/releases/1"
Wanted to run "'"$project_path"'/hooks/after-release.sh" but it does not exist
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

# Release should still be created and released
assert_directory_exists "$project_path/releases/1" || exit 1
assert_symlink "$project_path/current" || exit 1
