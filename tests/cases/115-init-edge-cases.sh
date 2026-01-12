# Test init command edge cases

# No URL provided should show usage
set +e
output=$(lit init 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "usage: lit init <url>" || exit 1
assert_string_contains "$output" "Examples:" || exit 1

# Directory already exists and is not empty should fail
mkdir -p "$world_path/case/existing-project"
echo "some content" > "$world_path/case/existing-project/file.txt"

set +e
output=$(lit init "https://github.com/SjorsO/existing-project.git" 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" 'already exists and is not empty' || exit 1

# Directory already exists but is empty should succeed
mkdir -p "$world_path/case/empty-project"

set +e
output=$(lit init "https://example.com/releases/empty-project.tar.gz" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_file_content "$world_path/case/empty-project/lit/bundle-url" "https://example.com/releases/empty-project.tar.gz" || exit 1

# Test .tar.zst extension is recognized as bundle and stripped properly
cd "$world_path/case"
set +e
output=$(lit init "https://example.com/releases/another-app.tar.zst" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_directory_exists "$world_path/case/another-app" || exit 1
assert_file_content "$world_path/case/another-app/lit/bundle-url" "https://example.com/releases/another-app.tar.zst" || exit 1
