# Test the lit checkout command

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Empty hooks so the deploy can succeed
echo '' > "$project_path/hooks/before-activation.sh"
echo '' > "$project_path/hooks/after-activation.sh"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Checkout without branch should fail
set +e
output=$(lit checkout 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "usage: lit checkout <branch>" || exit 1

# Checkout with extra arguments should fail
set +e
output=$(lit checkout main extra 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "usage: lit checkout <branch>" || exit 1

# Checkout current branch should fail
set +e
output=$(lit checkout main 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" 'Current branch is already "main"' || exit 1

# Checkout non-existent branch should fail
set +e
output=$(lit checkout this-branch-does-not-exist 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" 'does not exist on remote' || exit 1

# Checkout "another-branch" should succeed and deploy
set +e
output=$(lit checkout another-branch 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" 'Switching to branch "another-branch"' || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1
assert_symlink "$project_path/current" || exit 1
assert_file_content "$project_path/lit/current-branch" "another-branch" || exit 1

# Switch back to main - commit hash should be reused from checkout (not fetched again by deploy)
set +e
output=$(lit checkout main 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" 'Switching to branch "main"' || exit 1
# Deploy should NOT read from remote again - checkout already got the commit hash
assert_string_not_contains "$output" 'Reading "https://github.com/SjorsO/lit.git"' || exit 1
assert_directory_exists "$project_path/releases/2" || exit 1
assert_file_content "$project_path/lit/current-branch" "main" || exit 1

# Checkout on a bundle project should fail
cd "$world_path/case"
lit init "https://example.com/releases/my-app.tar.gz" > /dev/null
cd "$world_path/case/my-app"

set +e
output=$(lit checkout somebranch 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" 'Cannot change branches because you are not deploying from git' || exit 1
