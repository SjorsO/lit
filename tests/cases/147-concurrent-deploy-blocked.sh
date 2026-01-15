# Test that a second deploy is blocked when another deploy is running

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Make the before-release hook take 3 seconds
echo 'sleep 3' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Start first deploy in background
lit deploy > "$project_path/first-deploy-output.txt" 2>&1 &
first_deploy_pid=$!

# Wait 1 second for the first deploy to acquire the lock
sleep 1

# Try to run second deploy while first is still running
set +e
second_output=$(lit deploy 2>&1)
second_status_code=$?
set -e

# Second deploy should fail
assert_same 1 "$second_status_code" || exit 1

# Second deploy should mention another command is running
expected_output='Another Lit command is currently running for this project, aborting...
If this is wrong, manually run:
    rmdir "'"$project_path"'/lit-is-currently-running"'
assert_exact_output "$expected_output" "$second_output" || exit 1

# Wait for first deploy to finish
wait $first_deploy_pid
first_status_code=$?

# First deploy should succeed
assert_same 0 "$first_status_code" || exit 1

# Release should have been created by first deploy
assert_directory_exists "$project_path/releases/1" || exit 1

# Lock directory should be cleaned up
assert_file_missing "$project_path/lit-is-currently-running" || exit 1

# Log should contain both deploys in order: first deploy succeeded, then second was aborted
deployed_line=$(grep -n "lit deploy → deployed branch" "$project_path/logs/lit.log" | head -1 | cut -d: -f1)
aborted_line=$(grep -n "lit deploy → aborted, another lit command is currently running" "$project_path/logs/lit.log" | head -1 | cut -d: -f1)

assert_same 1 "$deployed_line" || exit 1
assert_same 2 "$aborted_line" || exit 1
