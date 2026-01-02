# Test that cached releases older than 7 days are pruned

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

lit enable-caching > /dev/null

# Override hooks to do nothing
echo '# no-op' > "$project_path/hooks/before-release.sh"
echo '# no-op' > "$project_path/hooks/after-release.sh"
echo '# no-op' > "$project_path/hooks/before-caching.sh"

# Create fake old cached releases (8 days old)
mkdir -p "$world_path/lit/releases"
touch "$world_path/lit/releases/old-cache-abc123.tar.zst"
touch "$world_path/lit/releases/old-cache-def456.tar.gz"

# Set modification time to 8 days ago
if is_macos; then
    touch -t "$(date -v-8d '+%Y%m%d%H%M')" "$world_path/lit/releases/old-cache-abc123.tar.zst"
    touch -t "$(date -v-8d '+%Y%m%d%H%M')" "$world_path/lit/releases/old-cache-def456.tar.gz"
else
    touch -d "8 days ago" "$world_path/lit/releases/old-cache-abc123.tar.zst"
    touch -d "8 days ago" "$world_path/lit/releases/old-cache-def456.tar.gz"
fi

# Create a recent cached release (should NOT be deleted)
touch "$world_path/lit/releases/recent-cache-ghi789.tar.zst"

# Verify all three files exist before deploy
assert_file_exists "$world_path/lit/releases/old-cache-abc123.tar.zst" || exit 1
assert_file_exists "$world_path/lit/releases/old-cache-def456.tar.gz" || exit 1
assert_file_exists "$world_path/lit/releases/recent-cache-ghi789.tar.zst" || exit 1

# Deploy (this should prune old cached releases)
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Old cached releases should be deleted
assert_file_missing "$world_path/lit/releases/old-cache-abc123.tar.zst" || exit 1
assert_file_missing "$world_path/lit/releases/old-cache-def456.tar.gz" || exit 1

# Recent cached release should still exist
assert_file_exists "$world_path/lit/releases/recent-cache-ghi789.tar.zst" || exit 1

# The actual cache created by this deploy should also exist
cache_count=$(find "$world_path/lit/releases" -name "*.tar.*" | wc -l)
assert_same 2 "$(echo "$cache_count" | tr -d ' ')" || exit 1
