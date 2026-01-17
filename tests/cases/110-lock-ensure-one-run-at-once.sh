# Test that only one lit command can run at a time

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Empty hooks so the deploy can succeed
echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Manually create the lock directory to simulate another command running
mkdir "$project_path/lit-is-currently-running"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

# Command should fail
assert_same 1 "$status_code" || exit 1

expected_output='Another Lit command is currently running for this project, aborting...
If this is wrong, manually run:
    rmdir "'"$project_path"'/lit-is-currently-running"'
assert_exact_output "$expected_output" "$output" || exit 1

# Log should contain the error message in single-line format
log_content=$(cat "$project_path/logs/lit.log")
assert_string_contains "$log_content" "lit deploy → aborted, another lit command is currently running" || exit 1

# Lock directory should still exist (we created it, lit didn't)
assert_directory_exists "$project_path/lit-is-currently-running" || exit 1

# Clean up lock and verify lit works again
rmdir "$project_path/lit-is-currently-running"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

# Now it should succeed
assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "'"$project_path"'/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/1"
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1
