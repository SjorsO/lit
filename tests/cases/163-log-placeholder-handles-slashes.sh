# Regression test for "lit checkout" producing a "sed: unknown option to `s'"
# error when the deploy result contains "/" (e.g. branch names like "feature/foo").
# The bug was in scripts/helpers.sh:replace_log_placeholder using "/" as the sed
# delimiter without escaping the result string.

# shellcheck disable=SC1091
source "$world_path/lit/scripts/helpers.sh"

project_base_path="$world_path/case/test-project"
mkdir -p "$project_base_path/logs"

pid="12345"

printf '[2026-05-05 12:00:00] lit checkout feature/foo (pending:%s)\n' "$pid" > "$project_base_path/logs/lit.log"

result='deployed branch "feature/foo" (commit: abc12345678)'

set +e
output=$(replace_log_placeholder "$pid" "$result" 1234 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_not_contains "$output" "unknown option" || exit 1

expected_log='[2026-05-05 12:00:00] lit checkout feature/foo → deployed branch "feature/foo" (commit: abc12345678) (in 1.23s)'
assert_file_content "$project_base_path/logs/lit.log" "$expected_log" || exit 1

# Also verify "&" and "\" don't break the replacement (both special in sed)
printf '[2026-05-05 12:00:00] lit deploy (pending:%s)\n' "$pid" > "$project_base_path/logs/lit.log"

result='deployed branch "a&b\c/d" (commit: abc12345678)'

set +e
output=$(replace_log_placeholder "$pid" "$result" 1234 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_not_contains "$output" "unknown option" || exit 1

expected_log='[2026-05-05 12:00:00] lit deploy → deployed branch "a&b\c/d" (commit: abc12345678) (in 1.23s)'
assert_file_content "$project_base_path/logs/lit.log" "$expected_log" || exit 1
