# Test deploy when current branch no longer exists on remote

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Manually set the branch to one that doesn't exist
echo "deleted-branch-that-does-not-exist" > "$project_path/git-branch"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

# Should fail because branch doesn't exist
assert_same 128 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "deleted-branch-that-does-not-exist" of "https://github.com/SjorsO/lit.git"...
Creating "'"$project_path"'/releases/1" for the new release...
Cloning repository... fatal: Remote branch deleted-branch-that-does-not-exist not found in upstream origin
Deleting new but unreleased release directory "'"$project_path"'/releases/1"
Finished with errors (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

# No release should be created
assert_file_missing "$project_path/releases/1" || exit 1
