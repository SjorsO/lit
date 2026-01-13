set +e

output=$(lit deploy 2>&1)

status_code=$?

set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'This is not a Lit directory' "$output" || exit 1

# Test directory with URL file but no storage directory
mkdir -p "$world_path/case/missing-storage"
echo "https://github.com/test/repo.git" > "$world_path/case/missing-storage/git-repository-url"

cd "$world_path/case/missing-storage"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'This looks like a Lit directory, but the storage directory does not exist' "$output" || exit 1
