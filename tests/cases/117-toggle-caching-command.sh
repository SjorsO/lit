# Test the enable-git-release-caching and disable-git-release-caching commands

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

cd "$project_path"

# Verify caching is disabled by default
assert_file_missing "$project_path/git-release-caching-enabled" || exit 1
assert_file_missing "$project_path/hooks/before-caching.sh" || exit 1

# 1. Enable caching (first time) - should succeed and create hook
set +e
output=$(lit enable-git-release-caching 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

expected_output='Release caching for git enabled

Created new hook "lit/hooks/before-caching.sh"

Review and update these hooks:
- "hooks/before-caching.sh"
- "hooks/before-release.sh"
- "hooks/after-release.sh"'
assert_exact_output "$expected_output" "$output" || exit 1
assert_file_exists "$project_path/git-release-caching-enabled" || exit 1
assert_file_exists "$project_path/hooks/before-caching.sh" || exit 1

# 2. Enable caching (again) - should fail
set +e
output=$(lit enable-git-release-caching 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'Release caching for git is already enabled' "$output" || exit 1

# 3. Disable caching - should succeed
set +e
output=$(lit disable-git-release-caching 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

expected_output='Release caching for git disabled

Review and update these hooks:
- "hooks/before-release.sh"
- "hooks/after-release.sh"

This hook will not be used anymore: "lit/hooks/before-caching.sh"'
assert_exact_output "$expected_output" "$output" || exit 1
assert_file_missing "$project_path/git-release-caching-enabled" || exit 1
assert_file_exists "$project_path/hooks/before-caching.sh" || exit 1

# 4. Disable caching (again) - should fail
set +e
output=$(lit disable-git-release-caching 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'Release caching for git is already disabled' "$output" || exit 1

# 5. Enable caching (hook still exists) - should succeed but not create hook
set +e
output=$(lit enable-git-release-caching 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

expected_output='Release caching for git enabled

Review and update these hooks:
- "hooks/before-caching.sh"
- "hooks/before-release.sh"
- "hooks/after-release.sh"'
assert_exact_output "$expected_output" "$output" || exit 1
assert_file_exists "$project_path/git-release-caching-enabled" || exit 1

# 6. Delete hook manually and disable caching - should succeed
rm "$project_path/hooks/before-caching.sh"

set +e
output=$(lit disable-git-release-caching 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

expected_output='Release caching for git disabled

Review and update these hooks:
- "hooks/before-release.sh"
- "hooks/after-release.sh"'
assert_exact_output "$expected_output" "$output" || exit 1
assert_file_missing "$project_path/git-release-caching-enabled" || exit 1
assert_file_missing "$project_path/hooks/before-caching.sh" || exit 1
