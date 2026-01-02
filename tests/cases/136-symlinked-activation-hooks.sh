# Test that symlinked before-activation and after-activation hooks work correctly

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo "APP_KEY=test" > "$project_path/.env"

# Create shared hooks outside the project directory
mkdir -p "$world_path/shared-hooks"

echo 'touch "$1/before-activation-ran"' > "$world_path/shared-hooks/before-activation.sh"
echo 'touch "$1/after-activation-ran"' > "$world_path/shared-hooks/after-activation.sh"

# Replace hooks with symlinks to shared hooks
rm "$project_path/hooks/before-activation.sh"
rm "$project_path/hooks/after-activation.sh"

ln -s "$world_path/shared-hooks/before-activation.sh" "$project_path/hooks/before-activation.sh"
ln -s "$world_path/shared-hooks/after-activation.sh" "$project_path/hooks/after-activation.sh"

# Verify they are symlinks
assert_symlink "$project_path/hooks/before-activation.sh" || exit 1
assert_symlink "$project_path/hooks/after-activation.sh" || exit 1

cd "$project_path"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Finished successfully" || exit 1

# Assert symlinked hooks ran correctly
assert_file_exists "$project_path/before-activation-ran" || exit 1
assert_file_exists "$project_path/after-activation-ran" || exit 1
