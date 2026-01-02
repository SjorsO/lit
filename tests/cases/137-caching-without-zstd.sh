# Test that caching works when zstd is not available (falls back to gzip)

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

lit enable-caching > /dev/null

# Override hooks to do nothing
echo '# no-op' > "$project_path/hooks/before-release.sh"
echo '# no-op' > "$project_path/hooks/after-release.sh"
echo '# no-op' > "$project_path/hooks/before-caching.sh"

# Build a new PATH that excludes ALL directories containing zstd
PATH_WITHOUT_ZSTD=""
IFS=':' read -ra path_dirs <<< "$PATH"
for dir in "${path_dirs[@]}"; do
    if [ ! -x "$dir/zstd" ]; then
        if [ -z "$PATH_WITHOUT_ZSTD" ]; then
            PATH_WITHOUT_ZSTD="$dir"
        else
            PATH_WITHOUT_ZSTD="$PATH_WITHOUT_ZSTD:$dir"
        fi
    fi
done

# Deploy with zstd removed from PATH (forces gzip fallback)
set +e
output=$(PATH="$PATH_WITHOUT_ZSTD" lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" 'Caching release... (tip: install "zstd" for faster caching)' || exit 1
assert_string_contains "$output" "Finished successfully" || exit 1

# Should have created a .tar.gz file (not .tar.zst)
gz_count=$(find "$world_path/lit/releases" -name "*.tar.gz" 2>/dev/null | wc -l)
zst_count=$(find "$world_path/lit/releases" -name "*.tar.zst" 2>/dev/null | wc -l)

assert_same 1 "$(echo "$gz_count" | tr -d ' ')" || exit 1
assert_same 0 "$(echo "$zst_count" | tr -d ' ')" || exit 1

# Second deploy should reuse cache from the .tar.gz
set +e
output=$(PATH="$PATH_WITHOUT_ZSTD" lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Reusing deployment from cache" || exit 1
