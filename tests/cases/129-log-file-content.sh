# Test that log files contain expected content

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Deploy should create log files
lit deploy > /dev/null

# lit.log should contain deployment info
log_content=$(cat "$project_path/logs/lit.log")
assert_string_contains "$log_content" "lit deploy" || exit 1
assert_string_contains "$log_content" "Deployed branch" || exit 1
assert_string_contains "$log_content" "main" || exit 1

# lit-output.log should contain command and finished marker
output_log=$(cat "$project_path/logs/lit-output.log")
assert_string_contains "$output_log" "lit deploy" || exit 1
assert_string_contains "$output_log" "Finished" || exit 1

# Deploy again with same commit - should log that it's already deployed
lit deploy > /dev/null

log_content=$(cat "$project_path/logs/lit.log")
assert_string_contains "$log_content" "Not deploying because latest commit" || exit 1
assert_string_contains "$log_content" "is already deployed" || exit 1
