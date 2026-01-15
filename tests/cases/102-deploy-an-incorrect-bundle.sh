# Test deploying a bundle with incorrect structure (missing top-level directory)
lit init "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-with-files-in-root-for-lit-tests.tar.zst" "bundle-for-lit-tests" > /dev/null

project_path="$world_path/case/bundle-for-lit-tests"

cd "$project_path"

echo "APP_KEY=test" > "$project_path/.env"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1

# Replace dynamic parts with placeholders and strip trailing whitespace
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]* seconds)/(in X seconds)/g')
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/([0-9]*K in [0-9]*\.[0-9]* seconds)/(XK in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-with-files-in-root-for-lit-tests.tar.zst.hash"... (in X seconds)
Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-with-files-in-root-for-lit-tests.tar.zst"... (XK in X seconds)
Adding bundle to cache ('"$world_path"'/lit/cached-releases/bb22dfeac05ac841f274afce3955dae95017ab5b.tar)
Creating "'"$project_path"'/releases/1" for the new release...
Extracting bundle...

Incorrect bundle structure.
Lit extracts bundles with "--strip-components=1", this strips the first path part from
every entry in the bundle.

You can verify your bundle by running "tar -tf {bundle}", each entry should look
either like "some-dir/config/filesystems.php" or like this "./config/filesystems.php".
If your entries look like "config/filesystem.php", then the bundle does not extract correctly.

For help with making bundles, see: https://github.com/SjorsO/lit?tab=readme-ov-file#deploying-a-bundle

Deleting new but unreleased release directory "'"$project_path"'/releases/1"
Finished with errors (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1
