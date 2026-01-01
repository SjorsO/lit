# Test the lit log command

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Empty hooks so the deploy can succeed
echo '' > "$project_path/hooks/before-activation.sh"
echo '' > "$project_path/hooks/after-activation.sh"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Log before any deploy should fail (no current release)
set +e
output=$(lit log 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "No git repository found" || exit 1

# Deploy first
lit deploy > /dev/null

# Now log should work
set +e
output=$(lit log -1 --oneline 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
# Output should contain a commit hash (7+ hex chars at start)
if ! echo "$output" | grep -qE '^[0-9a-f]{7,}'; then
    printf 'Expected git log output to start with commit hash, got: %s\n' "$output"
    exit 1
fi
