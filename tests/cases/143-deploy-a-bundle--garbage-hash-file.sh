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
assert_lines_in_order "$output" \
    'Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests-with-garbage-hash.tar.hash"' \
    'Warning: "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests-with-garbage-hash.tar.hash" does not contain a valid SHA1 hash' \
    'Hash file contents: According to all known laws of aviation, there is no way a bee should be able to fly.' \
    'Its wings are too small to get its fat little body off the ground.' \
    'Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests-with-garbage-hash.tar"' \
    "Adding bundle to cache ($world_path/lit/cached-releases/08f40c79ea2ee1f4d392e28b42672dacd3af923f.tar)" \
    "Creating \"$project_path/releases/1\" for the new release" \
    || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1

# Second deploy - should skip because actual hash matches (garbage hash is ignored)
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_lines_in_order "$output" \
    'Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests-with-garbage-hash.tar.hash"' \
    'Warning: "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests-with-garbage-hash.tar.hash" does not contain a valid SHA1 hash' \
    'Hash file contents: According to all known laws of aviation, there is no way a bee should be able to fly.' \
    'Its wings are too small to get its fat little body off the ground.' \
    'Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests-with-garbage-hash.tar"' \
    'Bundle exists in cache, but using the downloaded bundle instead' \
    'Bundle is already deployed (hash: 08f40c79ea2ee1f4d392e28b42672dacd3af923f)' \
    'Run "lit deploy --force" to redeploy' \
    'Finished successfully' \
    || exit 1
assert_file_missing "$project_path/releases/2" || exit 1

# Force deploy - should redeploy
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_lines_in_order "$output" \
    'Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests-with-garbage-hash.tar.hash"' \
    'Warning: "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests-with-garbage-hash.tar.hash" does not contain a valid SHA1 hash' \
    'Hash file contents: According to all known laws of aviation, there is no way a bee should be able to fly.' \
    'Its wings are too small to get its fat little body off the ground.' \
    'Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests-with-garbage-hash.tar"' \
    'Bundle exists in cache, but using the downloaded bundle instead' \
    'Bundle is already deployed (hash: 08f40c79ea2ee1f4d392e28b42672dacd3af923f)' \
    'Using "--force", redeploying...' \
    "Creating \"$project_path/releases/2\" for the new release" \
    || exit 1
assert_directory_exists "$project_path/releases/2" || exit 1
