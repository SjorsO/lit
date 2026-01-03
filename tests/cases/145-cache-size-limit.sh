# Test that cache is pruned when it exceeds 500MB

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

lit enable-caching > /dev/null

# Override hooks to do nothing
echo '# no-op' > "$project_path/hooks/before-release.sh"
echo '# no-op' > "$project_path/hooks/after-release.sh"
echo '# no-op' > "$project_path/hooks/before-caching.sh"

# Create fake cache files that exceed 500MB total
mkdir -p "$world_path/lit/cached-releases"

# Create 4 files of 200MB each (800MB total, exceeds 500MB limit)
head -c 200000000 /dev/zero > "$world_path/lit/cached-releases/oldest-file.tar"
head -c 200000000 /dev/zero > "$world_path/lit/cached-releases/second-oldest-file.tar"
head -c 200000000 /dev/zero > "$world_path/lit/cached-releases/second-newest-file.tar"
head -c 200000000 /dev/zero > "$world_path/lit/cached-releases/newest-file.tar"

# Set modification times (oldest to newest)
if is_macos; then
    touch -t "$(date -v-4d '+%Y%m%d%H%M')" "$world_path/lit/cached-releases/oldest-file.tar"
    touch -t "$(date -v-3d '+%Y%m%d%H%M')" "$world_path/lit/cached-releases/second-oldest-file.tar"
    touch -t "$(date -v-2d '+%Y%m%d%H%M')" "$world_path/lit/cached-releases/second-newest-file.tar"
    touch -t "$(date -v-1d '+%Y%m%d%H%M')" "$world_path/lit/cached-releases/newest-file.tar"
else
    touch -d "4 days ago" "$world_path/lit/cached-releases/oldest-file.tar"
    touch -d "3 days ago" "$world_path/lit/cached-releases/second-oldest-file.tar"
    touch -d "2 days ago" "$world_path/lit/cached-releases/second-newest-file.tar"
    touch -d "1 day ago" "$world_path/lit/cached-releases/newest-file.tar"
fi

# Verify all files exist before deploy
assert_file_exists "$world_path/lit/cached-releases/oldest-file.tar" || exit 1
assert_file_exists "$world_path/lit/cached-releases/second-oldest-file.tar" || exit 1
assert_file_exists "$world_path/lit/cached-releases/second-newest-file.tar" || exit 1
assert_file_exists "$world_path/lit/cached-releases/newest-file.tar" || exit 1

# Deploy (this should prune cache to under 500MB)
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Two oldest files should be deleted (800MB -> 400MB, under 500MB limit)
assert_file_missing "$world_path/lit/cached-releases/oldest-file.tar" || exit 1
assert_file_missing "$world_path/lit/cached-releases/second-oldest-file.tar" || exit 1

# Two newest files should still exist
assert_file_exists "$world_path/lit/cached-releases/second-newest-file.tar" || exit 1
assert_file_exists "$world_path/lit/cached-releases/newest-file.tar" || exit 1

# The deploy also creates a new cache file, so we should have 3 files total
cache_count=$(find "$world_path/lit/cached-releases" -name "*.tar" | wc -l)
assert_same 3 "$(echo "$cache_count" | tr -d ' ')" || exit 1
