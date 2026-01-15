# Test bundle deployment when .hash file does not exist

lit init "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz" > /dev/null

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

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]* seconds)/(in X seconds)/g')
output=$(echo "$output" | sed 's/([0-9]*K in [0-9]*\.[0-9]* seconds)/(XK in X seconds)/g')
output=$(echo "$output" | sed 's/[a-f0-9]\{40\}/HASH/g')
output=$(echo "$output" | sed 's/curl: ([0-9]*) .*/curl: (CURL_ERROR)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz.hash"... (in X seconds)
Warning: curl: (CURL_ERROR)
Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz"... (XK in X seconds)
Adding bundle to cache ('"$world_path"'/lit/cached-releases/HASH.tar)
Creating "'"$project_path"'/releases/1" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/1"
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

assert_directory_exists "$project_path/releases/1" || exit 1
assert_file_exists "$project_path/releases/1/artisan" || exit 1
assert_file_exists "$project_path/releases/1/bootstrap/app.php" || exit 1

# Second deploy - should still check for .hash, warn, then download to check hash
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]* seconds)/(in X seconds)/g')
output=$(echo "$output" | sed 's/([0-9]*K in [0-9]*\.[0-9]* seconds)/(XK in X seconds)/g')
output=$(echo "$output" | sed 's/[a-f0-9]\{40\}/HASH/g')
output=$(echo "$output" | sed 's/curl: ([0-9]*) .*/curl: (CURL_ERROR)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz.hash"... (in X seconds)
Warning: curl: (CURL_ERROR)
Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz"... (XK in X seconds)
Bundle exists in cache, but using the downloaded bundle instead
Bundle is already deployed (hash: HASH)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

assert_file_missing "$project_path/releases/2" || exit 1

# Force deploy - still checks hash file (for cache) but doesn't skip if already deployed
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]* seconds)/(in X seconds)/g')
output=$(echo "$output" | sed 's/([0-9]*K in [0-9]*\.[0-9]* seconds)/(XK in X seconds)/g')
output=$(echo "$output" | sed 's/[a-f0-9]\{40\}/HASH/g')
output=$(echo "$output" | sed 's/curl: ([0-9]*) .*/curl: (CURL_ERROR)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz.hash"... (in X seconds)
Warning: curl: (CURL_ERROR)
Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz"... (XK in X seconds)
Bundle exists in cache, but using the downloaded bundle instead
Bundle is already deployed (hash: HASH)
Using "--force", redeploying...
Creating "'"$project_path"'/releases/2" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/2"
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

assert_directory_exists "$project_path/releases/2" || exit 1
