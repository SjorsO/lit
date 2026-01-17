# Test that on-failure hook does NOT run when commit is already deployed (without --force)

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo "APP_KEY=test" > "$project_path/.env"

# Empty out the hooks
echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"

# Set up on-failure hook to record if it was called
echo 'touch "$1/on-failure-called"' > "$project_path/hooks/on-failure.sh"

cd "$project_path"

# First deploy should succeed
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_file_missing "$project_path/on-failure-called" || exit 1

# Second deploy without --force should skip (already deployed) and NOT call on-failure
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "already deployed" || exit 1
assert_file_missing "$project_path/on-failure-called" || exit 1
