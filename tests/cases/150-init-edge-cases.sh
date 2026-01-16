# Test init command edge cases

# No URL provided should show usage
set +e
output=$(lit init 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1

expected_output='usage: lit init <url> [project-name]

Examples:
  lit init https://github.com/user/repo.git
  lit init https://github.com/user/repo.git my-project
  lit init https://example.com/releases/app.tar.gz'
assert_exact_output "$expected_output" "$output" || exit 1

# Too many arguments should show usage
set +e
output=$(lit init "https://github.com/user/repo.git" "my-project" "extra-arg" 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1

expected_output='usage: lit init <url> [project-name]

Examples:
  lit init https://github.com/user/repo.git
  lit init https://github.com/user/repo.git my-project
  lit init https://example.com/releases/app.tar.gz'
assert_exact_output "$expected_output" "$output" || exit 1

# Invalid project name ".." should be rejected
set +e
output=$(lit init "https://example.com/bundle.tar.gz" ".." 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'Invalid project name ".."' "$output" || exit 1

# Invalid project name with slash should be rejected
set +e
output=$(lit init "https://example.com/bundle.tar.gz" "foo/bar" 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'Invalid project name "foo/bar"' "$output" || exit 1

# Project name with space is valid
set +e
output=$(lit init "https://example.com/bundle.tar.gz" "my project" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_directory_exists "$world_path/case/my project" || exit 1

# Project name with @ is valid
set +e
output=$(lit init "https://example.com/bundle.tar.gz" "my@project" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_directory_exists "$world_path/case/my@project" || exit 1

# Directory already exists and is not empty should fail
mkdir -p "$world_path/case/existing-project"
echo "some content" > "$world_path/case/existing-project/file.txt"

set +e
output=$(lit init "https://github.com/SjorsO/existing-project.git" 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'Directory "existing-project" already exists and is not a Laravel project' "$output" || exit 1

# Directory already exists but is empty should succeed
mkdir -p "$world_path/case/empty-project"

set +e
output=$(lit init "https://example.com/releases/empty-project.tar.gz" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_file_content "$world_path/case/empty-project/bundle-url" "https://example.com/releases/empty-project.tar.gz" || exit 1

# Test .tar.zst extension is recognized as bundle and stripped properly
cd "$world_path/case"
set +e
output=$(lit init "https://example.com/releases/another-app.tar.zst" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_directory_exists "$world_path/case/another-app" || exit 1
assert_file_content "$world_path/case/another-app/bundle-url" "https://example.com/releases/another-app.tar.zst" || exit 1

# Test that project name is extracted from before the last .tar
set +e
output=$(lit init "https://example.com/gus.tarballs.tar" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_directory_exists "$world_path/case/gus.tarballs" || exit 1
assert_file_content "$world_path/case/gus.tarballs/bundle-url" "https://example.com/gus.tarballs.tar" || exit 1

# Test that "." as project name fails if directory is not empty and not a zero downtime project
mkdir -p "$world_path/case/not-zero downtime"
echo "some content" > "$world_path/case/not-zero downtime/file.txt"
cd "$world_path/case/not-zero downtime"

set +e
output=$(lit init "https://example.com/bundle.tar.gz" "." 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'Directory "not-zero downtime" already exists and is not a Laravel project' "$output" || exit 1
