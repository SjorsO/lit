# Test that we always keep at least 1 cache entry even if it's >500MB

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

lit enable-caching > /dev/null

# Override hooks
echo '# no-op' > "$project_path/hooks/before-release.sh"
echo '# no-op' > "$project_path/hooks/after-release.sh"

echo 'head -c 600000000 /dev/urandom > "$1/big-file.bin"' > "$project_path/hooks/before-caching.sh"

# First deploy - creates a cache entry >500MB
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1

# Find the first cache file
first_cache_file=$(ls "$world_path/lit/cached-releases"/*.tar 2>/dev/null | head -1)
assert_file_exists "$first_cache_file" || exit 1

# Change the hook so we get a different cache entry
echo 'head -c 600000000 /dev/urandom > "$1/different-big-file.bin"' > "$project_path/hooks/before-caching.sh"

# Second deploy - creates another cache entry >500MB
# This should delete the first cache (>500MB limit) but keep the new one (always keep 1)
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_directory_exists "$project_path/releases/2" || exit 1

# First cache file should be deleted
assert_file_missing "$first_cache_file" || exit 1

# Should have exactly 1 cache file (the new one)
cache_count=$(ls "$world_path/lit/cached-releases"/*.tar 2>/dev/null | wc -l)
assert_same 1 "$(echo "$cache_count" | tr -d ' ')" || exit 1
