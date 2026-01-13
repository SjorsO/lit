# Test the lit help command

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

cd "$project_path"

set +e
output=$(lit help 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "usage: lit <command>" || exit 1
assert_string_contains "$output" "init <url>" || exit 1
assert_string_contains "$output" "deploy" || exit 1
assert_string_contains "$output" "checkout <branch>" || exit 1
assert_string_contains "$output" "enable-git-release-caching" || exit 1
assert_string_contains "$output" "disable-git-release-caching" || exit 1
assert_string_contains "$output" "enable-telemetry" || exit 1
assert_string_contains "$output" "disable-telemetry" || exit 1
