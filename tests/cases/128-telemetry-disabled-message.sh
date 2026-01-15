# Test telemetry is written to /tmp/lit-telemetry during tests

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Clear any existing telemetry log
rm -f /tmp/lit-telemetry

# Enable telemetry
lit enable-telemetry > /dev/null

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "'"$project_path"'/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project_path"'/releases/1"
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1

# Wait briefly for background telemetry job
sleep 0.5

# Assert telemetry was written to disk
assert_file_exists /tmp/lit-telemetry || exit 1

telemetry_content=$(cat /tmp/lit-telemetry)
assert_string_contains "$telemetry_content" '"action": "deploy"' || exit 1
assert_string_contains "$telemetry_content" '"lit_installation_id": "testing"' || exit 1
assert_string_contains "$telemetry_content" '"source_type": "git"' || exit 1
