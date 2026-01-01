# Test that if on-failure hook fails, the deploy continues and logs a message

lit clone "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Make before-activation hook fail to trigger on-failure
echo 'exit 1' > "$project_path/hooks/before-activation.sh"
echo '' > "$project_path/hooks/after-activation.sh"

# Make on-failure hook also fail
echo 'exit 1' > "$project_path/hooks/on-failure.sh"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

set +e
output=$(lit pull 2>&1)
status_code=$?
set -e

# Deploy should fail (due to before-activation hook)
assert_same 1 "$status_code" || exit 1

# Output should mention the on-failure hook failed
assert_string_contains "$output" "The on-failure hook failed" || exit 1

# Output should still show "Finished with errors"
assert_string_contains "$output" "Finished with errors" || exit 1

# Log should contain the on-failure hook failure
log_content=$(cat "$project_path/logs/lit.log")
assert_string_contains "$log_content" "The on-failure hook failed" || exit 1

# Output should NOT contain getcwd error (would happen if we're still in deleted directory)
assert_string_not_contains "$output" "cannot access parent directories" || exit 1
