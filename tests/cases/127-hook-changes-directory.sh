# Test that hooks can change directory without breaking deploy

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Create hooks that change to a different directory
# The deploy script should restore the working directory after each hook
echo 'cd /tmp && touch /tmp/before-release-ran' > "$project_path/hooks/before-release.sh"
echo 'cd /tmp && touch /tmp/after-release-ran' > "$project_path/hooks/after-release.sh"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

# Deploy should succeed despite hooks changing directory
assert_same 0 "$status_code" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1
assert_symlink "$project_path/current" || exit 1

# Both hooks should have run
assert_file_exists "/tmp/before-release-ran" || exit 1
assert_file_exists "/tmp/after-release-ran" || exit 1

# Clean up
rm -f /tmp/before-release-ran /tmp/after-release-ran
