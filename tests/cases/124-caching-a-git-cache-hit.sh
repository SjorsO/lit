# Test deploy with caching - cache hit scenario (reusing cached release)

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Hooks that create marker files to verify they run even with cached releases
echo 'touch "$2/before-release-ran"' > "$project_path/hooks/before-release.sh"
echo 'touch "$2/after-release-ran"' > "$project_path/hooks/after-release.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Enable caching with a hook that creates a file with a random value
lit enable-caching > /dev/null
echo 'uuidgen > "$1/cache-marker"' > "$project_path/hooks/before-caching.sh"

# Deploy main - should build and cache
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_lines_in_order "$output" \
    'Reading branch "main" of "https://github.com/SjorsO/lit.git"' \
    'Cloning repository' \
    'Running "lit/hooks/before-caching.sh"' \
    'Caching release' \
    "Creating \"$project_path/releases/1\" for the new release" \
    'Extracting release' \
    'Creating a symlink to the storage directory' \
    'Creating a symlink to the .env file' \
    "Releasing the new deployment \"$project_path/releases/1\"" \
    'Finished successfully' \
    || exit 1
assert_string_not_contains "$output" "Reusing deployment from cache" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1
assert_file_exists "$project_path/releases/1/cache-marker" || exit 1

# Save the main branch cache marker value and cache file path
main_cache_marker=$(cat "$project_path/releases/1/cache-marker")
main_cache_file=$(find "$world_path/lit/cached-releases" -name "*.tar" | head -1)

# Checkout another-branch - should build and cache for that branch
set +e
output=$(lit checkout another-branch 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_lines_in_order "$output" \
    'Switching to branch "another-branch"' \
    'Cloning repository' \
    'Running "lit/hooks/before-caching.sh"' \
    'Caching release' \
    "Creating \"$project_path/releases/2\" for the new release" \
    'Extracting release' \
    'Creating a symlink to the storage directory' \
    'Creating a symlink to the .env file' \
    "Releasing the new deployment \"$project_path/releases/2\"" \
    'Finished successfully' \
    || exit 1
assert_string_not_contains "$output" "Reusing deployment from cache" || exit 1
assert_directory_exists "$project_path/releases/2" || exit 1
assert_file_exists "$project_path/releases/2/cache-marker" || exit 1

# Save the another-branch cache marker value (should be different from main)
another_branch_cache_marker=$(cat "$project_path/releases/2/cache-marker")
if [ "$main_cache_marker" = "$another_branch_cache_marker" ]; then
    printf 'Expected different cache markers for different branches\n'
    exit 1
fi

# Set the main branch cache file to 3 days old
if is_macos; then
    touch -t "$(date -v-3d '+%Y%m%d%H%M')" "$main_cache_file"
else
    touch -d "3 days ago" "$main_cache_file"
fi

# Create a reference file to compare timestamps
touch "$world_path/timestamp-reference"

# Checkout main again - should use cache with same marker value
set +e
output=$(lit checkout main 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_lines_in_order "$output" \
    'Switching to branch "main"' \
    'Reusing deployment from cache' \
    "Creating \"$project_path/releases/3\" for the new release" \
    'Extracting release' \
    'Creating a symlink to the storage directory' \
    'Creating a symlink to the .env file' \
    "Releasing the new deployment \"$project_path/releases/3\"" \
    "Deleting old release directory \"$project_path/releases/1\"" \
    'Finished successfully' \
    || exit 1
assert_string_not_contains "$output" "Caching release" || exit 1
assert_directory_exists "$project_path/releases/3" || exit 1
assert_file_content "$project_path/releases/3/cache-marker" "$main_cache_marker" || exit 1

# Cache file should have been touched (newer than or equal to reference)
if [ "$main_cache_file" -ot "$world_path/timestamp-reference" ]; then
    printf 'Expected cache file to be touched after reuse\n'
    exit 1
fi
# Verify before/after release hooks still run with cached releases
assert_file_exists "$project_path/releases/3/before-release-ran" || exit 1
assert_file_exists "$project_path/releases/3/after-release-ran" || exit 1

# Checkout another-branch again - should use cache with same marker value
set +e
output=$(lit checkout another-branch 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_lines_in_order "$output" \
    'Switching to branch "another-branch"' \
    'Reusing deployment from cache' \
    "Creating \"$project_path/releases/4\" for the new release" \
    'Extracting release' \
    'Creating a symlink to the storage directory' \
    'Creating a symlink to the .env file' \
    "Releasing the new deployment \"$project_path/releases/4\"" \
    "Deleting old release directory \"$project_path/releases/2\"" \
    'Finished successfully' \
    || exit 1
assert_string_not_contains "$output" "Caching release" || exit 1
assert_directory_exists "$project_path/releases/4" || exit 1
assert_file_content "$project_path/releases/4/cache-marker" "$another_branch_cache_marker" || exit 1
# Verify before/after release hooks still run with cached releases
assert_file_exists "$project_path/releases/4/before-release-ran" || exit 1
assert_file_exists "$project_path/releases/4/after-release-ran" || exit 1

# Force deploy - should use cache with same marker value
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_lines_in_order "$output" \
    'Reading branch "another-branch" of "https://github.com/SjorsO/lit.git"' \
    'Latest commit of "another-branch" is already deployed' \
    'Using "--force", redeploying...' \
    'Reusing deployment from cache' \
    "Creating \"$project_path/releases/5\" for the new release" \
    'Extracting release' \
    'Creating a symlink to the storage directory' \
    'Creating a symlink to the .env file' \
    "Releasing the new deployment \"$project_path/releases/5\"" \
    "Deleting old release directory \"$project_path/releases/3\"" \
    'Finished successfully' \
    || exit 1
assert_string_not_contains "$output" "Caching release" || exit 1
assert_file_content "$project_path/current/cache-marker" "$another_branch_cache_marker" || exit 1
# Verify before/after release hooks still run with cached releases
assert_file_exists "$project_path/current/before-release-ran" || exit 1
assert_file_exists "$project_path/current/after-release-ran" || exit 1
