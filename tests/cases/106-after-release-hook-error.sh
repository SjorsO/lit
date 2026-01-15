# Test that if after-release hook fails, the release is still released but script exits with error

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Make after-release hook fail
echo '' > "$project_path/hooks/before-release.sh"
echo 'exit 1' > "$project_path/hooks/after-release.sh"

# Set up on-failure hook to record that it was called
echo 'echo "$2" > "$1/on-failure-called"' > "$project_path/hooks/on-failure.sh"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

# Deploy should fail (due to after-release hook)
assert_same 1 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "'"$project_path"'/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/1"
>
> Warning: The new deployment was still released!
>
Finished with errors (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

# But release should still exist and be released
assert_directory_exists "$project_path/releases/1" || exit 1
assert_symlink "$project_path/current" || exit 1

current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/1" || exit 1

# on-failure hook should have been called with was_released=true
assert_file_exists "$project_path/on-failure-called" || exit 1
assert_file_content "$project_path/on-failure-called" "true" || exit 1
