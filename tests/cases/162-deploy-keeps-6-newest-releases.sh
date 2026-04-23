# Test that deploy cleans up old releases, keeping only the 6 newest

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo "APP_KEY=test" > "$project_path/.env"
echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"

cd "$project_path"

# Seed 6 dummy release directories as if prior deploys had run
for release_id in 1 2 3 4 5 6 ; do
    mkdir -p "$project_path/releases/$release_id"
    touch "$project_path/releases/$release_id/marker"
done
ln -snf "$project_path/releases/6" "$project_path/current"

# Deploy should create release 7 and delete release 1 (oldest beyond the 6 kept)
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "'"$project_path"'/releases/7" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/7"
Deleting old release directory "'"$project_path"'/releases/1"...
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

assert_file_missing "$project_path/releases/1" || exit 1
assert_directory_exists "$project_path/releases/2" || exit 1
assert_directory_exists "$project_path/releases/3" || exit 1
assert_directory_exists "$project_path/releases/4" || exit 1
assert_directory_exists "$project_path/releases/5" || exit 1
assert_directory_exists "$project_path/releases/6" || exit 1
assert_directory_exists "$project_path/releases/7" || exit 1

current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/7" || exit 1

# Another deploy should create release 8 and delete release 2
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/([a-f0-9]\{11\})/(COMMIT)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Creating "'"$project_path"'/releases/8" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/8"
Deleting old release directory "'"$project_path"'/releases/2"...
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

assert_file_missing "$project_path/releases/1" || exit 1
assert_file_missing "$project_path/releases/2" || exit 1
assert_directory_exists "$project_path/releases/3" || exit 1
assert_directory_exists "$project_path/releases/4" || exit 1
assert_directory_exists "$project_path/releases/5" || exit 1
assert_directory_exists "$project_path/releases/6" || exit 1
assert_directory_exists "$project_path/releases/7" || exit 1
assert_directory_exists "$project_path/releases/8" || exit 1
