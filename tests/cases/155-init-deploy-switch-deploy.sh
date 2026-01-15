# Test switching from git to bundle deployment

project_path="$world_path/case/my-app"

# Init git repo
set +e
output=$(lit init "https://github.com/SjorsO/lit.git" "my-app" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

cd "$project_path"

echo "APP_KEY=test" > "$project_path/.env"
echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"

# Deploy git repo
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" 'Cloning repository' || exit 1
assert_string_contains "$output" 'Finished successfully' || exit 1
assert_symlink "$project_path/current" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1

# Verify current points to release 1
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/1" || exit 1

# Switch to bundle
set +e
output=$(lit init "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst" "." 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" 'Changing from git URL: https://github.com/SjorsO/lit.git' || exit 1
assert_string_contains "$output" 'Bundle URL set to' || exit 1

# Git files should be deleted
assert_file_missing "$project_path/git-repository-url" || exit 1
assert_file_missing "$project_path/git-branch" || exit 1

# Bundle files should exist
assert_file_exists "$project_path/bundle-url" || exit 1

# Clear bundle cache to ensure we test the download path
rm -f "$world_path/lit/cached-releases/"*.tar

# Deploy bundle
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" 'Downloading bundle' || exit 1
assert_string_contains "$output" 'Finished successfully' || exit 1
assert_directory_exists "$project_path/releases/2" || exit 1

# Verify current points to release 2
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/2" || exit 1

# Switch back to git
set +e
output=$(lit init "https://github.com/SjorsO/lit.git" "." 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" 'Changing from bundle URL:' || exit 1
assert_string_contains "$output" 'Current branch set to "main"' || exit 1

# Bundle files should be deleted
assert_file_missing "$project_path/bundle-url" || exit 1
assert_file_missing "$project_path/bundle-hash" || exit 1

# Git files should exist
assert_file_exists "$project_path/git-repository-url" || exit 1
assert_file_exists "$project_path/git-branch" || exit 1

# Deploy git repo again
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" 'Cloning repository' || exit 1
assert_string_contains "$output" 'Finished successfully' || exit 1
assert_directory_exists "$project_path/releases/3" || exit 1

# Verify current points to release 3
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/3" || exit 1
