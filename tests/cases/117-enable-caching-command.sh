# Test the enable-caching and disable-caching commands

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo '' > "$project_path/hooks/before-activation.sh"
echo '' > "$project_path/hooks/after-activation.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Verify caching is disabled by default
assert_file_missing "$project_path/lit/caching-enabled" || exit 1
assert_file_missing "$project_path/hooks/before-caching.sh" || exit 1

# Disable caching when already disabled should fail
set +e
output=$(lit disable-caching 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "Release caching is already disabled" || exit 1

# Enable caching should succeed and create hook
set +e
output=$(lit enable-caching 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Release caching enabled" || exit 1
assert_string_contains "$output" "Created new hook" || exit 1
assert_string_contains "$output" "before-caching.sh" || exit 1
assert_file_exists "$project_path/lit/caching-enabled" || exit 1
assert_file_exists "$project_path/hooks/before-caching.sh" || exit 1

# Verify status shows caching enabled
set +e
output=$(lit status 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Release caching: enabled" || exit 1

# Enable caching again should fail (already enabled)
set +e
output=$(lit enable-caching 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "Release caching is already enabled" || exit 1

# Disable caching should succeed
set +e
output=$(lit disable-caching 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Release caching disabled" || exit 1
assert_file_missing "$project_path/lit/caching-enabled" || exit 1

# Hook file should still exist (not deleted)
assert_file_exists "$project_path/hooks/before-caching.sh" || exit 1

# Verify status shows caching disabled
set +e
output=$(lit status 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Release caching: disabled" || exit 1

# Test enabling when hook already exists (should not overwrite)
echo '# custom hook content' > "$project_path/hooks/before-caching.sh"

set +e
output=$(lit enable-caching 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Release caching enabled" || exit 1
# Should NOT mention creating new hook
assert_string_not_contains "$output" "Created new hook" || exit 1
# Hook should still have custom content
assert_file_content "$project_path/hooks/before-caching.sh" "# custom hook content" || exit 1

# Test enable-caching on bundle project should fail
cd "$world_path/case"
lit init "https://example.com/releases/my-app.tar.gz" > /dev/null
cd "$world_path/case/my-app"

set +e
output=$(lit enable-caching 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "Release caching is only available when deploying from git" || exit 1
