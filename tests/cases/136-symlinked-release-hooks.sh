# Test that symlinked before-release and after-release hooks work correctly

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo "APP_KEY=test" > "$project_path/.env"

# Create shared hooks outside the project directory
mkdir -p "$world_path/shared-hooks"

echo 'touch "$1/before-release-ran"' > "$world_path/shared-hooks/before-release.sh"
echo 'touch "$1/after-release-ran"' > "$world_path/shared-hooks/after-release.sh"

# Replace hooks with symlinks to shared hooks
rm "$project_path/hooks/before-release.sh"
rm "$project_path/hooks/after-release.sh"

ln -s "$world_path/shared-hooks/before-release.sh" "$project_path/hooks/before-release.sh"
ln -s "$world_path/shared-hooks/after-release.sh" "$project_path/hooks/after-release.sh"

# Verify they are symlinks
assert_symlink "$project_path/hooks/before-release.sh" || exit 1
assert_symlink "$project_path/hooks/after-release.sh" || exit 1

cd "$project_path"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Finished successfully" || exit 1

# Assert symlinked hooks ran correctly
assert_file_exists "$project_path/before-release-ran" || exit 1
assert_file_exists "$project_path/after-release-ran" || exit 1
