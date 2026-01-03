# Test bundle deployment when .hash file contains garbage (not a valid SHA1)
# The garbage data contains "fat little body" and some other stuff.

lit init "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests-with-garbage-hash.tar" > /dev/null

project_path="$world_path/case/bundle-for-lit-tests-with-garbage-hash"

echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# First deploy - should warn about invalid hash format but still deploy
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Checking bundle version" || exit 1
assert_string_contains "$output" "does not contain a valid SHA1 hash" || exit 1
assert_string_contains "$output" "fat little body" || exit 1
assert_string_contains "$output" "Downloading bundle" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1

# Second deploy - should skip because actual hash matches (garbage hash is ignored)
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "does not contain a valid SHA1 hash" || exit 1
assert_string_contains "$output" "Bundle is already deployed" || exit 1
assert_file_missing "$project_path/releases/2" || exit 1

# Force deploy - should redeploy
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" 'Using "--force", redeploying' || exit 1
assert_directory_exists "$project_path/releases/2" || exit 1
