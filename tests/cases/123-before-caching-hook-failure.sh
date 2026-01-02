# Test deploy when before-caching.sh hook fails

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo '' > "$project_path/hooks/before-activation.sh"
echo '' > "$project_path/hooks/after-activation.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Enable caching
lit enable-caching > /dev/null

# Make the before-caching hook fail
echo 'exit 1' > "$project_path/hooks/before-caching.sh"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

# Should fail because hook failed
assert_same 1 "$status_code" || exit 1

# No release should be created
assert_file_missing "$project_path/releases/1" || exit 1

# Log should contain failure message
log_content=$(cat "$project_path/logs/lit.log")
assert_string_contains "$log_content" "Deploy failed" || exit 1

# on-failure hook should have been called
echo 'touch "$1/on-failure-ran"' > "$project_path/hooks/on-failure.sh"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_file_exists "$project_path/on-failure-ran" || exit 1
