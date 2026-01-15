# Test switching from git to bundle deployment

project_path="$world_path/case/my-app"

# Init git repo
set +e
output=$(lit init "https://github.com/SjorsO/lit.git" "my-app" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

cd "$project_path"

echo "APP_KEY=test" > "$project_path/.env"
echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"

# Deploy git repo
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "'"$project_path"'/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/1"
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

assert_symlink "$project_path/current" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1

# Verify current points to release 1
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/1" || exit 1

# Switch to bundle
set +e
output=$(lit init "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst" "." 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

expected_output='Changing from git URL: https://github.com/SjorsO/lit.git (branch: main)
Bundle URL set to "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst"

Finished initializing "my-app"

Next steps:
- Review these hooks:
  - "hooks/before-release.sh"
  - "hooks/after-release.sh"
  - "hooks/on-failure.sh"

After that, run "lit deploy" to download and deploy the bundle'
assert_exact_output "$expected_output" "$output" || exit 1

# Git files should be deleted
assert_file_missing "$project_path/git-repository-url" || exit 1
assert_file_missing "$project_path/git-branch" || exit 1

# Bundle files should exist
assert_file_exists "$project_path/bundle-url" || exit 1

# Clear bundle cache to ensure we test the download path
rm -f "$world_path/lit/cached-releases/"*.tar

# Deploy bundle
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
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst"... (XK in X seconds)
Adding bundle to cache ('"$world_path"'/lit/cached-releases/HASH.tar)
Creating "'"$project_path"'/releases/2" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/2"
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

assert_directory_exists "$project_path/releases/2" || exit 1

# Verify current points to release 2
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/2" || exit 1

# Switch back to git
set +e
output=$(lit init "https://github.com/SjorsO/lit.git" "." 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

expected_output='Reading "https://github.com/SjorsO/lit.git"... Done!

Changing from bundle URL: https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests.tar.zst
Current branch set to "main"

Finished initializing "my-app"

Next steps:
- Review these hooks:
  - "hooks/before-release.sh"
  - "hooks/after-release.sh"
  - "hooks/on-failure.sh"

After that, either:
- Run "lit deploy" to deploy the current branch (main)
- Run "lit checkout <branch>" to deploy a different branch'
assert_exact_output "$expected_output" "$output" || exit 1

# Bundle files should be deleted
assert_file_missing "$project_path/bundle-url" || exit 1
assert_file_missing "$project_path/bundle-hash" || exit 1

# Git files should exist
assert_file_exists "$project_path/git-repository-url" || exit 1
assert_file_exists "$project_path/git-branch" || exit 1

# Deploy git repo again
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "'"$project_path"'/releases/3" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/3"
Deleting old release directory "'"$project_path"'/releases/1"...
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

assert_directory_exists "$project_path/releases/3" || exit 1

# Verify current points to release 3
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/3" || exit 1
