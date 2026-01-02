# Test deploy command edge cases

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo '' > "$project_path/hooks/before-activation.sh"
echo '' > "$project_path/hooks/after-activation.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Deploy with invalid arguments should fail
set +e
output=$(lit deploy invalid-arg 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "usage: lit deploy [--force]" || exit 1

# Deploy with too many arguments should fail
set +e
output=$(lit deploy --force extra 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "usage: lit deploy [--force]" || exit 1

# Invalid source type should fail
echo "invalid" > "$project_path/lit/source-type"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" 'Invalid source type: "invalid"' || exit 1

# Restore valid source type
echo "git" > "$project_path/lit/source-type"

# Non-numeric release directory should fail
mkdir "$project_path/releases/not-a-number"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "not fully numeric" || exit 1

# Clean up invalid release directory
rmdir "$project_path/releases/not-a-number"

# before-caching.sh exists but caching disabled should show warning
echo '# caching hook' > "$project_path/hooks/before-caching.sh"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" 'Hook "hooks/before-caching.sh" exists but will not be used because release caching is disabled' || exit 1
