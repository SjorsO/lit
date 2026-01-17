# Test that you can symlink {project}/logs to {project}/storage/logs to have all your logs in the same directory.

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Fill in .env
echo "APP_KEY=test" > "$project_path/.env"

# Empty out the hooks (default hooks run composer install which fails for this repo)
echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"

# Remove the logs directory created by init
rm -rf "$project_path/logs"

# Create storage/logs like Laravel has
mkdir -p "$project_path/storage/logs"

# Symlink logs to storage/logs (so all logs end up in one place)
ln -s "$project_path/storage/logs" "$project_path/logs"

cd "$project_path"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Assert that logs is a symlink pointing to storage/logs
assert_symlink "$project_path/logs" || exit 1
logs_target=$(readlink "$project_path/logs")
assert_same "$project_path/storage/logs" "$logs_target" || exit 1

# Assert that lit.log was created in storage/logs (via the symlink)
assert_file_exists "$project_path/storage/logs/lit.log" || exit 1
assert_file_exists "$project_path/storage/logs/lit-output.log" || exit 1

# Verify the log files are also accessible via the symlink
assert_file_exists "$project_path/logs/lit.log" || exit 1
assert_file_exists "$project_path/logs/lit-output.log" || exit 1

# Verify the log contains the deploy entry
log_content=$(cat "$project_path/logs/lit.log")
assert_string_contains "$log_content" "lit deploy" || exit 1
assert_string_contains "$log_content" 'deployed branch "main"' || exit 1
