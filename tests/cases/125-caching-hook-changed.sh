# Test cache invalidation when before-caching.sh hook content changes

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Enable caching with initial hook that creates a unique marker
lit enable-git-release-caching > /dev/null
echo 'touch "$1/hook-version-1" && uuidgen > "$1/cache-marker"' > "$project_path/hooks/before-caching.sh"

# First deploy - builds cache
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
assert_file_exists "$project_path/releases/1/hook-version-1" || exit 1
assert_file_exists "$project_path/releases/1/cache-marker" || exit 1

# Save the original cache marker
original_cache_marker=$(cat "$project_path/releases/1/cache-marker")

# Change the hook content
echo 'touch "$1/hook-version-2" && uuidgen > "$1/cache-marker"' > "$project_path/hooks/before-caching.sh"

# Second deploy - should detect hook changed and rebuild
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_lines_in_order "$output" \
    'Reading branch "main" of "https://github.com/SjorsO/lit.git"' \
    'Latest commit of "main" is already deployed' \
    'Using "--force", redeploying...' \
    'Cached release found but hook changed, rebuilding...' \
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

# New release should have the new hook marker and a different cache marker
assert_file_exists "$project_path/releases/2/hook-version-2" || exit 1
assert_file_missing "$project_path/releases/2/hook-version-1" || exit 1

new_cache_marker=$(cat "$project_path/releases/2/cache-marker")
if [ "$original_cache_marker" = "$new_cache_marker" ]; then
    printf 'Expected different cache markers after hook change\n'
    exit 1
fi

# Change the hook back to the original version
echo 'touch "$1/hook-version-1" && uuidgen > "$1/cache-marker"' > "$project_path/hooks/before-caching.sh"

# Third deploy - should reuse the original cache
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_lines_in_order "$output" \
    'Reading branch "main" of "https://github.com/SjorsO/lit.git"' \
    'Latest commit of "main" is already deployed' \
    'Using "--force", redeploying...' \
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

# Should have the original hook marker and cache marker
assert_file_exists "$project_path/releases/3/hook-version-1" || exit 1
assert_file_content "$project_path/releases/3/cache-marker" "$original_cache_marker" || exit 1
