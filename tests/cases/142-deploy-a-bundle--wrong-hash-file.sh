# Test bundle deployment when .hash file contains wrong hash
# The .hash file contains "1234567890000000000000000000000000000000" which doesn't match the actual bundle
# The actual bundle hash is "95632a16d315752fc2c8e5b298546b389094a9d2".

lit init "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar" > /dev/null

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
assert_lines_in_order "$output" \
    'Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar.hash"' \
    'Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar"' \
    'Warning: the hash from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar.hash" does not match the actual hash' \
    'Warning: actual bundle hash "95632a16d315752fc2c8e5b298546b389094a9d2", hash from hash file "1234567890000000000000000000000000000000"' \
    "Adding bundle to cache ($world_path/lit/cached-releases/95632a16d315752fc2c8e5b298546b389094a9d2.tar)" \
    "Creating \"$project_path/releases/1\" for the new release" \
    || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1

# The stored hash should be the ACTUAL bundle hash, not the wrong hash from the .hash file
assert_file_content "$project_path/bundle-hash" "95632a16d315752fc2c8e5b298546b389094a9d2" || exit 1

# Second deploy - should download (wrong hash means no cache hit) but not deploy (because actual hash matches)
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_lines_in_order "$output" \
    'Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar.hash"' \
    'Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar"' \
    'Warning: the hash from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar.hash" does not match the actual hash' \
    'Warning: actual bundle hash "95632a16d315752fc2c8e5b298546b389094a9d2", hash from hash file "1234567890000000000000000000000000000000"' \
    'Bundle exists in cache, but using the downloaded bundle instead' \
    'Bundle is already deployed (hash: 95632a16d315752fc2c8e5b298546b389094a9d2)' \
    'Run "lit deploy --force" to redeploy' \
    'Finished successfully' \
    || exit 1
assert_file_missing "$project_path/releases/2" || exit 1

# Force deploy - still checks hash file (for cache) but doesn't skip if already deployed
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_lines_in_order "$output" \
    'Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar.hash"' \
    'Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar"' \
    'Warning: the hash from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar.hash" does not match the actual hash' \
    'Warning: actual bundle hash "95632a16d315752fc2c8e5b298546b389094a9d2", hash from hash file "1234567890000000000000000000000000000000"' \
    'Bundle exists in cache, but using the downloaded bundle instead' \
    'Bundle is already deployed (hash: 95632a16d315752fc2c8e5b298546b389094a9d2)' \
    'Using "--force", redeploying...' \
    "Creating \"$project_path/releases/2\" for the new release" \
    || exit 1
assert_directory_exists "$project_path/releases/2" || exit 1
