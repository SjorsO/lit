lit init "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst" > /dev/null

project_path="$world_path/case/bundle-for-lit-tests"

# Replace hooks to verify $1 and $2 are correct directories
# $1 (project_base_directory) should contain storage/ and logs/
# $2 (new_release_directory) should be a directory with the extracted bundle
echo '[ -d "$1/storage" ] && [ -d "$1/logs" ] && [ -d "$2" ] && touch "$1/before-release-ran" && touch "$2/before-release-release"' > "$project_path/hooks/before-release.sh"
echo '[ -d "$1/storage" ] && [ -d "$1/logs" ] && [ -d "$2" ] && touch "$1/after-release-ran" && touch "$2/after-release-release"' > "$project_path/hooks/after-release.sh"

cd "$project_path"

# First pull with empty .env should fail
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'Your ".env" file is empty, try again when you have filled it in' "$output" || exit 1
assert_file_missing "$project_path/current" || exit 1

# Fill in .env and pull again
echo "APP_KEY=test" > "$project_path/.env"

# Create git-release-caching-enabled file (should be deleted during bundle deploy)
touch "$project_path/git-release-caching-enabled"

# Clear bundle cache to ensure we test the download path
rm -f "$world_path/lit/cached-releases/"*.tar

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Assert timing formats are correct
# Hash check: "(in X.XX seconds)" format
if [[ ! "$output" =~ \(in\ [0-9]+\.[0-9]{2}\ seconds\) ]]; then
    printf 'Expected hash check timing format "(in X.XX seconds)", got: %s\n' "$output"
    exit 1
fi
# Download: "(XXK in X.XX seconds)" format
if [[ ! "$output" =~ \([0-9]+K\ in\ [0-9]+\.[0-9]{2}\ seconds\) ]]; then
    printf 'Expected download timing format "(XXK in X.XX seconds)", got: %s\n' "$output"
    exit 1
fi
# Final runtime: "(in X.XXs)" format
if [[ ! "$output" =~ \(in\ [0-9]+\.[0-9]{2}s\) ]]; then
    printf 'Expected runtime format "(in X.XXs)", got: %s\n' "$output"
    exit 1
fi

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]* seconds)/(in X seconds)/g')
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/([0-9]*K in [0-9]*\.[0-9]* seconds)/(XK in X seconds)/g')
output=$(echo "$output" | sed 's/[a-f0-9]\{40\}/HASH/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst"... (XK in X seconds)
Adding bundle to cache ('"$world_path"'/lit/cached-releases/HASH.tar)
Creating "'"$project_path"'/releases/1" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/1"
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

# Caching-enabled file should be deleted during bundle deploy
assert_file_missing "$project_path/git-release-caching-enabled" || exit 1

# Assert hooks ran with $1 (project_base_directory)
assert_file_exists "$project_path/before-release-ran" || exit 1
assert_file_exists "$project_path/after-release-ran" || exit 1

# Assert hooks ran with $2 (new_release_directory)
assert_file_exists "$project_path/releases/1/before-release-release" || exit 1
assert_file_exists "$project_path/releases/1/after-release-release" || exit 1

# Assert current symlink exists and points to release 1
assert_symlink "$project_path/current" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/1" || exit 1

# Assert .env and storage in release are symlinks
assert_symlink "$project_path/releases/1/.env" || exit 1
assert_symlink "$project_path/releases/1/storage" || exit 1

# Assert bundle was extracted with --strip-components=1 (database.php should be in config/, not root)
assert_file_missing "$project_path/releases/1/database.php" || exit 1
assert_file_exists "$project_path/releases/1/config/database.php" || exit 1

# Pull again - should skip because same bundle hash
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]* seconds)/(in X seconds)/g')
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[a-f0-9]\{40\}/HASH/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Bundle is already deployed (hash: HASH)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1
assert_file_missing "$project_path/releases/2" || exit 1

# Pull with --force should redeploy
rm -f "$world_path/lit/cached-releases/"*.tar
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]* seconds)/(in X seconds)/g')
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/([0-9]*K in [0-9]*\.[0-9]* seconds)/(XK in X seconds)/g')
output=$(echo "$output" | sed 's/[a-f0-9]\{40\}/HASH/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst"... (XK in X seconds)
Adding bundle to cache ('"$world_path"'/lit/cached-releases/HASH.tar)
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
assert_symlink "$project_path/current" || exit 1
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/2" || exit 1

# Rename release 2 to 9 to test numeric sorting (1,9,10 not 1,10,9)
mv "$project_path/releases/2" "$project_path/releases/9"
ln -snf "$project_path/releases/9" "$project_path/current"

rm -f "$world_path/lit/cached-releases/"*.tar
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]* seconds)/(in X seconds)/g')
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/([0-9]*K in [0-9]*\.[0-9]* seconds)/(XK in X seconds)/g')
output=$(echo "$output" | sed 's/[a-f0-9]\{40\}/HASH/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst"... (XK in X seconds)
Adding bundle to cache ('"$world_path"'/lit/cached-releases/HASH.tar)
Bundle is already deployed (hash: HASH)
Using "--force", redeploying...
Creating "'"$project_path"'/releases/10" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/10"
Deleting old release directory "'"$project_path"'/releases/1"...
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1
assert_directory_exists "$project_path/releases/10" || exit 1
assert_directory_exists "$project_path/releases/9" || exit 1
# first release was deleted because we only keep 2 releases.
assert_file_missing "$project_path/releases/1" || exit 1
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/10" || exit 1

# Another deploy to verify cleanup continues working
rm -f "$world_path/lit/cached-releases/"*.tar
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]* seconds)/(in X seconds)/g')
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/([0-9]*K in [0-9]*\.[0-9]* seconds)/(XK in X seconds)/g')
output=$(echo "$output" | sed 's/[a-f0-9]\{40\}/HASH/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst"... (XK in X seconds)
Adding bundle to cache ('"$world_path"'/lit/cached-releases/HASH.tar)
Bundle is already deployed (hash: HASH)
Using "--force", redeploying...
Creating "'"$project_path"'/releases/11" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/11"
Deleting old release directory "'"$project_path"'/releases/9"...
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1
assert_directory_exists "$project_path/releases/11" || exit 1
assert_directory_exists "$project_path/releases/10" || exit 1
assert_file_missing "$project_path/releases/9" || exit 1
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/11" || exit 1
