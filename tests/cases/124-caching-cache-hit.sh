# Test deploy with caching - cache hit scenario (reusing cached release)

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Hooks that create marker files to verify they run even with cached releases
echo 'touch "$2/before-activation-ran"' > "$project_path/hooks/before-activation.sh"
echo 'touch "$2/after-activation-ran"' > "$project_path/hooks/after-activation.sh"
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
assert_string_contains "$output" "Caching release" || exit 1
assert_string_not_contains "$output" "Reusing deployment from cache" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1
assert_file_exists "$project_path/releases/1/cache-marker" || exit 1

# Save the main branch cache marker value
main_cache_marker=$(cat "$project_path/releases/1/cache-marker")

# Checkout another-branch - should build and cache for that branch
set +e
output=$(lit checkout another-branch 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Caching release" || exit 1
assert_string_not_contains "$output" "Reusing deployment from cache" || exit 1
assert_directory_exists "$project_path/releases/2" || exit 1
assert_file_exists "$project_path/releases/2/cache-marker" || exit 1

# Save the another-branch cache marker value (should be different from main)
another_branch_cache_marker=$(cat "$project_path/releases/2/cache-marker")
if [ "$main_cache_marker" = "$another_branch_cache_marker" ]; then
    printf 'Expected different cache markers for different branches\n'
    exit 1
fi

# Checkout main again - should use cache with same marker value
set +e
output=$(lit checkout main 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Reusing deployment from cache" || exit 1
assert_string_not_contains "$output" "Caching release" || exit 1
assert_directory_exists "$project_path/releases/3" || exit 1
assert_file_content "$project_path/releases/3/cache-marker" "$main_cache_marker" || exit 1
# Verify activation hooks still run with cached releases
assert_file_exists "$project_path/releases/3/before-activation-ran" || exit 1
assert_file_exists "$project_path/releases/3/after-activation-ran" || exit 1

# Checkout another-branch again - should use cache with same marker value
set +e
output=$(lit checkout another-branch 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Reusing deployment from cache" || exit 1
assert_string_not_contains "$output" "Caching release" || exit 1
assert_directory_exists "$project_path/releases/4" || exit 1
assert_file_content "$project_path/releases/4/cache-marker" "$another_branch_cache_marker" || exit 1
# Verify activation hooks still run with cached releases
assert_file_exists "$project_path/releases/4/before-activation-ran" || exit 1
assert_file_exists "$project_path/releases/4/after-activation-ran" || exit 1

# Force deploy - should use cache with same marker value
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Reusing deployment from cache" || exit 1
assert_string_not_contains "$output" "Caching release" || exit 1
assert_file_content "$project_path/current/cache-marker" "$another_branch_cache_marker" || exit 1
# Verify activation hooks still run with cached releases
assert_file_exists "$project_path/current/before-activation-ran" || exit 1
assert_file_exists "$project_path/current/after-activation-ran" || exit 1
