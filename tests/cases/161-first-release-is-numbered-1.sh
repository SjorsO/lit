# Test that the first release is numbered 1 when releases directory is empty

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo "APP_KEY=test" > "$project_path/.env"

# Empty out the hooks
echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"

# Verify releases directory exists but is empty
assert_directory_exists "$project_path/releases" || exit 1
releases_count=$(ls "$project_path/releases" | wc -l | tr -d ' ')
assert_same "0" "$releases_count" || exit 1

cd "$project_path"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# First release should be numbered 1
assert_directory_exists "$project_path/releases/1" || exit 1
assert_file_missing "$project_path/releases/0" || exit 1

# Current symlink should point to release 1
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/1" || exit 1

# Delete release 1 and deploy again - should use 1 again
# (current directory still exists, pointing at nothing)
rm -rf "$project_path/releases/1"

set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Should be numbered 1 again since releases directory was empty
assert_directory_exists "$project_path/releases/1" || exit 1
current_target=$(readlink "$project_path/current")
assert_string_contains "$current_target" "releases/1" || exit 1
