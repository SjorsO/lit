# Test lit init when git call fails (invalid repository)

set +e
# GIT_TERMINAL_PROMPT=0 prevents git from asking for credentials
output=$(GIT_TERMINAL_PROMPT=0 lit init "https://github.com/SjorsO/this-repo-does-not-exist-12345.git" 2>&1)
status_code=$?
set -e

# Should fail because repository doesn't exist
assert_same 128 "$status_code" || exit 1

# Directory should not be created
assert_file_missing "$world_path/case/this-repo-does-not-exist-12345/lit" || exit 1

# Bundle init with non-existent URL should succeed (validation happens at deploy time)
set +e
output=$(lit init "https://example.com/this-does-not-exist.tar.gz" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_file_exists "$world_path/case/this-does-not-exist/lit/source-type" || exit 1
assert_file_content "$world_path/case/this-does-not-exist/lit/source-type" "bundle" || exit 1

# Invalid custom project names should be rejected
set +e
output=$(lit init "https://example.com/app.tar.gz" "invalid name" 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "contains invalid characters" || exit 1
assert_file_missing "$world_path/case/invalid name" || exit 1

set +e
output=$(lit init "https://example.com/app.tar.gz" "path/traversal" 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "contains invalid characters" || exit 1

set +e
output=$(lit init "https://example.com/app.tar.gz" "special@chars!" 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "contains invalid characters" || exit 1

# Valid custom project name should work
set +e
output=$(lit init "https://example.com/app.tar.gz" "my-valid_project.name123" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_file_exists "$world_path/case/my-valid_project.name123/lit/source-type" || exit 1

# Too many arguments should fail
set +e
output=$(lit init "https://example.com/app.tar.gz" "project" "extra-arg" 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "usage: lit init" || exit 1
