set +e

output=$(lit deploy 2>&1)

status_code=$?

set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'This is not a Lit directory' "$output" || exit 1
