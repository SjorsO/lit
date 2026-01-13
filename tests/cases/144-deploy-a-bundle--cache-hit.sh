# Test bundle deployment reuses cached bundle

lit init "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests.tar.zst" > /dev/null

project_path="$world_path/case/bundle-for-lit-tests"

echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# First deploy - should download and cache
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Downloading bundle" || exit 1
assert_string_not_contains "$output" "Using cached bundle" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1

# Verify bundle was cached using the hash from active-bundle-hash
bundle_hash=$(cat "$project_path/active-bundle-hash")
cached_file="$world_path/lit/cached-releases/$bundle_hash.tar"

assert_file_exists "$cached_file" || exit 1

# Assert this is the only file in the cache directory
cache_count=$(find "$world_path/lit/cached-releases" -type f | wc -l)
assert_same 1 "$(echo "$cache_count" | tr -d ' ')" || exit 1

# Set the cached file to 3 days old
if is_macos; then
    touch -t "$(date -v-3d '+%Y%m%d%H%M')" "$cached_file"
else
    touch -d "3 days ago" "$cached_file"
fi

# Create a reference file to compare timestamps
touch "$world_path/timestamp-reference"

# Create a second project with the same bundle URL
cd "$world_path/case"
lit init "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests.tar.zst" "second-project" > /dev/null

second_project_path="$world_path/case/second-project"

echo '' > "$second_project_path/hooks/before-release.sh"
echo '' > "$second_project_path/hooks/after-release.sh"
echo "APP_KEY=test" > "$second_project_path/.env"

cd "$second_project_path"

# Second project deploy - should use cache
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Using cached bundle" || exit 1
assert_string_not_contains "$output" "Downloading bundle" || exit 1
assert_directory_exists "$second_project_path/releases/1" || exit 1

# Cache file should have been touched (newer than reference)
if [ "$cached_file" -ot "$world_path/timestamp-reference" ]; then
    printf 'Expected cache file to be touched after reuse\n'
    exit 1
fi

# Rename the cached file so it's not a cache hit anymore
mv "$cached_file" "$cached_file.renamed"

# Deploy without force - should skip because already deployed (hash check passes)
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Bundle is already deployed" || exit 1
assert_string_not_contains "$output" "Downloading bundle" || exit 1
assert_file_missing "$second_project_path/releases/2" || exit 1

# Force deploy - should re-download since cache is gone
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Downloading bundle" || exit 1
assert_string_not_contains "$output" "Using cached bundle" || exit 1
assert_directory_exists "$second_project_path/releases/2" || exit 1
