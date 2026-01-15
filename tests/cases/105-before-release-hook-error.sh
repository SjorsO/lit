# Test that if before-release hook fails, the deploy fails and release is cleaned up

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Make before-release hook fail
echo 'exit 1' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"

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

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "'"$project_path"'/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Deleting new but unreleased release directory "'"$project_path"'/releases/1"
Finished with errors (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1
assert_file_missing "$project_path/releases/1" || exit 1
assert_file_missing "$project_path/current" || exit 1

# on-failure hook should have been called with was_released=false
assert_file_exists "$project_path/on-failure-called" || exit 1
assert_file_content "$project_path/on-failure-called" "false" || exit 1
rm "$project_path/on-failure-called"

# Fix the hook, second deploy should succeed
echo '' > "$project_path/hooks/before-release.sh"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

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
assert_symlink "$project_path/current" || exit 1
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/1" || exit 1

# on-failure hook should NOT have been called
assert_file_missing "$project_path/on-failure-called" || exit 1

# Break the hook again, third deploy should fail
echo 'exit 1' > "$project_path/hooks/before-release.sh"

set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/([a-f0-9]\{11\})/(COMMIT)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Creating "'"$project_path"'/releases/2" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Deleting new but unreleased release directory "'"$project_path"'/releases/2"
Finished with errors (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

# Failed release should be cleaned up
assert_file_missing "$project_path/releases/2" || exit 1

# But release 1 should still exist
assert_directory_exists "$project_path/releases/1" || exit 1

# Current symlink should still point to release 1
assert_symlink "$project_path/current" || exit 1
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/1" || exit 1

# on-failure hook should have been called with was_released=false
assert_file_exists "$project_path/on-failure-called" || exit 1
assert_file_content "$project_path/on-failure-called" "false" || exit 1
