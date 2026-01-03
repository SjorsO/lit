# Test bundle deployment when .hash file contains wrong hash
# The .hash file contains "1234567890000000000000000000000000000000" which doesn't match the actual bundle
# The actual bundle hash is "08f40c79ea2ee1f4d392e28b42672dacd3af923f".

lit init "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests-with-wrong-hash.tar" > /dev/null

project_path="$world_path/case/bundle-for-lit-tests-with-wrong-hash"

echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# First deploy - should warn about wrong hash but still deploy
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Checking bundle version" || exit 1
assert_string_contains "$output" "Warning: the hash from" || exit 1
assert_string_contains "$output" "does not match the actual hash" || exit 1
assert_string_contains "$output" "Downloading bundle" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1

# The stored hash should be the ACTUAL bundle hash, not the wrong hash from the .hash file
assert_file_content "$project_path/lit/current-bundle-hash" "08f40c79ea2ee1f4d392e28b42672dacd3af923f" || exit 1

# Second deploy - should still download (because .hash is wrong) but not deploy (because actual hash matches)
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Warning: the hash from" || exit 1
assert_string_contains "$output" "Bundle is already deployed" || exit 1
assert_file_missing "$project_path/releases/2" || exit 1

# Force deploy - should redeploy even when hash matches
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" 'Using "--force", redeploying' || exit 1
assert_directory_exists "$project_path/releases/2" || exit 1
