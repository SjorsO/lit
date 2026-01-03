lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Replace hooks to verify $1 and $2 are correct directories
# $1 (project_base_directory) should contain lit/ and logs/
# $2 (new_release_directory) should contain the cloned repo (lit.sh)
echo '[ -d "$1/lit" ] && [ -d "$1/logs" ] && [ -f "$2/lit.sh" ] && touch "$1/before-release-ran" && touch "$2/before-release-release"' > "$project_path/hooks/before-release.sh"
echo '[ -d "$1/lit" ] && [ -d "$1/logs" ] && [ -f "$2/lit.sh" ] && touch "$1/after-release-ran" && touch "$2/after-release-release"' > "$project_path/hooks/after-release.sh"

cd "$project_path"

# First pull with empty .env should fail
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'Your ".env" file is empty, try again when you have filled it in' "$output" || exit 1
assert_file_missing "$project_path/current" || exit 1

# Fill in .env and pull again
echo "APP_KEY=test" > "$project_path/.env"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_lines_in_order "$output" \
    'Reading branch "main" of "https://github.com/SjorsO/lit.git"' \
    "Creating \"$project_path/releases/1\" for the new release" \
    'Cloning repository' \
    'Creating a symlink to the storage directory' \
    'Creating a symlink to the .env file' \
    "Releasing the new deployment \"$project_path/releases/1\"" \
    'Finished successfully' \
    || exit 1

# Assert hooks ran with $1 (project_base_directory)
assert_file_exists "$project_path/before-release-ran" || exit 1
assert_file_exists "$project_path/after-release-ran" || exit 1

# Assert hooks ran with $2 (new_release_directory)
assert_file_exists "$project_path/releases/1/before-release-release" || exit 1
assert_file_exists "$project_path/releases/1/after-release-release" || exit 1

# Assert current symlink exists and points to release 1
assert_symlink "$project_path/current" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/1" || exit 1

# Assert .env and storage in release are symlinks
assert_symlink "$project_path/releases/1/.env" || exit 1
assert_symlink "$project_path/releases/1/storage" || exit 1

# Pull again - should skip because already deployed
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_lines_in_order "$output" \
    'Reading branch "main" of "https://github.com/SjorsO/lit.git"' \
    'Latest commit of "main" is already deployed' \
    'Run "lit deploy --force" to redeploy' \
    'Finished successfully' \
    || exit 1
assert_file_missing "$project_path/releases/2" || exit 1

# Pull with --force should redeploy
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_lines_in_order "$output" \
    'Reading branch "main" of "https://github.com/SjorsO/lit.git"' \
    'Latest commit of "main" is already deployed' \
    'Using "--force", redeploying...' \
    "Creating \"$project_path/releases/2\" for the new release" \
    'Cloning repository' \
    'Creating a symlink to the storage directory' \
    'Creating a symlink to the .env file' \
    "Releasing the new deployment \"$project_path/releases/2\"" \
    'Finished successfully' \
    || exit 1
assert_directory_exists "$project_path/releases/2" || exit 1
assert_symlink "$project_path/current" || exit 1

# Verify current points to release 2
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/2" || exit 1

# Rename release 2 to 9 to test numeric sorting (1,9,10 not 1,10,9)
mv "$project_path/releases/2" "$project_path/releases/9"
ln -snf "$project_path/releases/9" "$project_path/current"

set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_directory_exists "$project_path/releases/10" || exit 1
assert_directory_exists "$project_path/releases/9" || exit 1
# first release was deleted because we only keep 2 releases.
assert_file_missing "$project_path/releases/1" || exit 1
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/10" || exit 1

# Another deploy to verify cleanup continues working
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_directory_exists "$project_path/releases/11" || exit 1
assert_directory_exists "$project_path/releases/10" || exit 1
assert_file_missing "$project_path/releases/9" || exit 1
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/11" || exit 1

# Test lit status command
set +e
output=$(lit status 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Deploying from: git" || exit 1
assert_string_contains "$output" "Git repository url: https://github.com/SjorsO/lit.git" || exit 1
assert_string_contains "$output" "Current branch: main" || exit 1
assert_string_contains "$output" "Release caching: disabled" || exit 1
