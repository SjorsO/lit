# Test cache invalidation when before-caching.sh hook content changes

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo '' > "$project_path/hooks/before-activation.sh"
echo '' > "$project_path/hooks/after-activation.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Enable caching with initial hook that creates a unique marker
lit enable-caching > /dev/null
echo 'touch "$1/hook-version-1" && uuidgen > "$1/cache-marker"' > "$project_path/hooks/before-caching.sh"

# First deploy - builds cache
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
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
assert_string_contains "$output" "Cached release found but hook changed, rebuilding" || exit 1
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
assert_string_contains "$output" "Reusing deployment from cache" || exit 1
assert_string_not_contains "$output" "Caching release" || exit 1

# Should have the original hook marker and cache marker
assert_file_exists "$project_path/releases/3/hook-version-1" || exit 1
assert_file_content "$project_path/releases/3/cache-marker" "$original_cache_marker" || exit 1
