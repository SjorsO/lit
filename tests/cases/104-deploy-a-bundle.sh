lit init "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests.tar.zst" > /dev/null

project_path="$world_path/case/bundle-for-lit-tests"

# Replace hooks to verify $1 and $2 are correct directories
# $1 (project_base_directory) should contain lit/ and logs/
# $2 (new_release_directory) should be a directory with the extracted bundle
echo '[ -d "$1/lit" ] && [ -d "$1/logs" ] && [ -d "$2" ] && touch "$1/before-release-ran" && touch "$2/before-release-release"' > "$project_path/hooks/before-release.sh"
echo '[ -d "$1/lit" ] && [ -d "$1/logs" ] && [ -d "$2" ] && touch "$1/after-release-ran" && touch "$2/after-release-release"' > "$project_path/hooks/after-release.sh"

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

# Create caching-enabled file (should be deleted during bundle deploy)
touch "$project_path/lit/caching-enabled"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# This bundle URL has a .hash file, so it should use it for pre-checking
assert_string_contains "$output" "Checking bundle version" || exit 1
assert_string_not_contains "$output" "hash file does not exist" || exit 1

# Caching-enabled file should be deleted during bundle deploy
assert_file_missing "$project_path/lit/caching-enabled" || exit 1

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

# Pull again - should skip because same bundle hash
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "is already deployed" || exit 1
assert_string_contains "$output" "Run \"lit deploy --force\" to redeploy" || exit 1
assert_file_missing "$project_path/releases/2" || exit 1

# Pull with --force should redeploy
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
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
assert_string_contains "$output" "Deploying from: bundle" || exit 1
assert_string_contains "$output" "Bundle URL: https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests.tar.zst" || exit 1
assert_string_contains "$output" "Bundle hash URL: https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests.tar.zst.hash" || exit 1
assert_string_contains "$output" "Current bundle hash:" || exit 1
