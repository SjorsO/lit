# Test deploy command edge cases

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Deploy with invalid arguments should fail
set +e
output=$(lit deploy invalid-arg 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'usage: lit deploy [--force]' "$output" || exit 1

# Deploy with too many arguments should fail
set +e
output=$(lit deploy --force extra 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'usage: lit deploy [--force]' "$output" || exit 1

# Non-numeric release directory should fail
mkdir "$project_path/releases/not-a-number"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='The name of existing release directory "'"$project_path"'/releases/not-a-number/" is not fully numeric, this should never happen
Finished with errors (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

# Clean up invalid release directory
rmdir "$project_path/releases/not-a-number"

# before-caching.sh exists but caching disabled should show warning
echo '# caching hook' > "$project_path/hooks/before-caching.sh"

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
Hook "hooks/before-caching.sh" exists but will not be used because release caching is disabled
Releasing the new deployment "'"$project_path"'/releases/1"
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1
