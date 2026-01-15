# Test caching when before-caching.sh hook is missing

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Enable caching but delete the hook
lit enable-git-release-caching > /dev/null
rm "$project_path/hooks/before-caching.sh"

# Deploy should warn about missing hook but still work
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Cloning repository...
Wanted to run "'"$project_path"'/hooks/before-caching.sh" but it does not exist
Caching release...
Creating "'"$project_path"'/releases/1" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/1"
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1
