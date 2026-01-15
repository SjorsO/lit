# Test the lit checkout command

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Empty hooks so the deploy can succeed
echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Checkout without branch should fail
set +e
output=$(lit checkout 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'usage: lit checkout <branch>' "$output" || exit 1

# Checkout with extra arguments should fail
set +e
output=$(lit checkout main extra 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'usage: lit checkout <branch>' "$output" || exit 1

# Checkout current branch should fail
set +e
output=$(lit checkout main 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'Current branch is already "main"' "$output" || exit 1

# Checkout non-existent branch should fail
set +e
output=$(lit checkout this-branch-does-not-exist 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
output=$(echo "$output" | sed 's/[[:space:]]*$//')
expected_output='Switching to branch "this-branch-does-not-exist"...
Branch "this-branch-does-not-exist" does not exist on remote'
assert_exact_output "$expected_output" "$output" || exit 1

# Checkout "another-branch" should succeed and deploy
set +e
output=$(lit checkout another-branch 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Switching to branch "another-branch"...
Creating "'"$project_path"'/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/1"
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1
assert_symlink "$project_path/current" || exit 1
assert_file_content "$project_path/git-branch" "another-branch" || exit 1

# Switch back to main - commit hash should be reused from checkout (not fetched again by deploy)
set +e
output=$(lit checkout main 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Switching to branch "main"...
Creating "'"$project_path"'/releases/2" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/2"
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1
assert_directory_exists "$project_path/releases/2" || exit 1
assert_file_content "$project_path/git-branch" "main" || exit 1

# Checkout on a bundle project should fail
cd "$world_path/case"
lit init "https://example.com/releases/my-app.tar.gz" > /dev/null
cd "$world_path/case/my-app"

set +e
output=$(lit checkout somebranch 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'Cannot change branches because you are not deploying from git' "$output" || exit 1
