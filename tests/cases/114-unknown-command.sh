# Test that unknown commands show help

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

cd "$project_path"

# Unknown command should show help
set +e
output=$(lit unknowncommand 2>&1)
status_code=$?
set -e

# Should exit 1 and show help
assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "usage: lit <command>" || exit 1

# No command at all should also show help
set +e
output=$(lit 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "usage: lit <command>" || exit 1
