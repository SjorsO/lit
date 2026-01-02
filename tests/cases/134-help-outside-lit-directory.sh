# Test that lit help works outside of a lit directory

# We're in $world_path/case which is not a lit directory

set +e
output=$(lit help 2>&1)
status_code=$?
set -e

# Help should work even outside a lit directory
assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "usage: lit <command>" || exit 1

# Also test that init works outside lit directory (it should)
set +e
output=$(lit init 2>&1)
status_code=$?
set -e

# Should show usage (exit 1) but not "This is not a Lit directory"
assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "usage: lit init" || exit 1
assert_string_not_contains "$output" "This is not a Lit directory" || exit 1

# Test that lit help inside a lit directory doesn't log
lit init "https://github.com/SjorsO/lit.git" > /dev/null

cd "$world_path/case/lit"

set +e
output=$(lit help 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "usage: lit <command>" || exit 1

# Help should not be logged
log_content=$(cat "$world_path/case/lit/logs/lit-output.log" 2>/dev/null || echo "")
assert_string_not_contains "$log_content" "lit help" || exit 1
