# Test that if on-failure hook fails, the deploy continues and logs a message

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Make before-release hook fail to trigger on-failure
echo 'exit 1' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"

# Make on-failure hook also fail
echo 'exit 1' > "$project_path/hooks/on-failure.sh"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

# Deploy should fail (due to before-release hook)
assert_same 1 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "'"$project_path"'/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Deleting new but unreleased release directory "'"$project_path"'/releases/1"
The on-failure hook failed
Finished with errors (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

# Log should contain the failure (on-failure hook failure is shown in stdout, not lit.log)
log_content=$(cat "$project_path/logs/lit.log")
assert_string_contains "$log_content" "lit deploy → failed" || exit 1

# Output should NOT contain getcwd error (would happen if we're still in deleted directory)
assert_string_not_contains "$output" "cannot access parent directories" || exit 1
