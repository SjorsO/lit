# Test lit init when git call fails (invalid repository)

set +e
# GIT_TERMINAL_PROMPT=0 prevents git from asking for credentials
output=$(GIT_TERMINAL_PROMPT=0 lit init "https://github.com/SjorsO/this-repo-does-not-exist-12345.git" 2>&1)
status_code=$?
set -e

# Should fail because repository doesn't exist
assert_same 128 "$status_code" || exit 1

# Directory should not be created
assert_file_missing "$world_path/case/this-repo-does-not-exist-12345/storage" || exit 1

# Bundle init with non-existent URL should succeed (validation happens at deploy time)
set +e
output=$(lit init "https://example.com/this-does-not-exist.tar.gz" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_file_exists "$world_path/case/this-does-not-exist/bundle-url" || exit 1
assert_file_content "$world_path/case/this-does-not-exist/bundle-url" "https://example.com/this-does-not-exist.tar.gz" || exit 1

# Invalid custom project names should be rejected
set +e
output=$(lit init "https://example.com/app.tar.gz" "path/traversal" 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'Invalid project name "path/traversal"' "$output" || exit 1

set +e
output=$(lit init "https://example.com/app.tar.gz" ".." 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'Invalid project name ".."' "$output" || exit 1

# Valid custom project names (spaces and special chars are now allowed)
set +e
output=$(lit init "https://example.com/app.tar.gz" "my-valid_project.name123" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_file_exists "$world_path/case/my-valid_project.name123/bundle-url" || exit 1

set +e
output=$(lit init "https://example.com/app.tar.gz" "name with spaces" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_file_exists "$world_path/case/name with spaces/bundle-url" || exit 1

set +e
output=$(lit init "https://example.com/app.tar.gz" "special@chars!" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_file_exists "$world_path/case/special@chars!/bundle-url" || exit 1

# Too many arguments should fail
set +e
output=$(lit init "https://example.com/app.tar.gz" "project" "extra-arg" 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1

expected_output='usage: lit init <url> [project-name]

Examples:
  lit init https://github.com/user/repo.git
  lit init https://github.com/user/repo.git my-project
  lit init https://example.com/releases/app.tar.gz'
assert_exact_output "$expected_output" "$output" || exit 1
