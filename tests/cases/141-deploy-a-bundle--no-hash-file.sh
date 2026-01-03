# Test bundle deployment when .hash file does not exist

lit init "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests-without-hash.tar.gz" > /dev/null

project_path="$world_path/case/bundle-for-lit-tests-without-hash"

echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# First deploy - should warn about missing .hash file but still deploy
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Checking bundle version" || exit 1
assert_string_contains "$output" "Warning:" || exit 1
assert_string_contains "$output" "Downloading bundle" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1
assert_file_exists "$project_path/releases/1/artisan" || exit 1
assert_file_exists "$project_path/releases/1/bootstrap/app.php" || exit 1

# Second deploy - should still check for .hash, warn, then download to check hash
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Warning:" || exit 1
assert_string_contains "$output" "Bundle is already deployed" || exit 1
assert_file_missing "$project_path/releases/2" || exit 1

# Force deploy - still checks hash file (for cache) but doesn't skip if already deployed
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Checking bundle version" || exit 1
assert_directory_exists "$project_path/releases/2" || exit 1
