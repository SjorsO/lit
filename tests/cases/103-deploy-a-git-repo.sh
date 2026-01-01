lit clone "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

# Replace hooks to verify $1 and $2 are correct directories
# $1 (project_base_directory) should contain lit/ and logs/
# $2 (new_release_directory) should contain the cloned repo (lit.sh)
echo '[ -d "$1/lit" ] && [ -d "$1/logs" ] && [ -f "$2/lit.sh" ] && touch "$1/before-activation-ran" && touch "$2/before-activation-release"' > "$project_path/hooks/before-activation.sh"
echo '[ -d "$1/lit" ] && [ -d "$1/logs" ] && [ -f "$2/lit.sh" ] && touch "$1/after-activation-ran" && touch "$2/after-activation-release"' > "$project_path/hooks/after-activation.sh"

cd "$project_path"

# First pull with empty .env should fail
set +e
output=$(lit pull 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_exact_output 'Your ".env" file is empty, try again when you have filled it in' "$output" || exit 1
assert_file_missing "$project_path/current" || exit 1

# Fill in .env and pull again
echo "APP_KEY=test" > "$project_path/.env"

set +e
output=$(lit pull 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Assert hooks ran with $1 (project_base_directory)
assert_file_exists "$project_path/before-activation-ran" || exit 1
assert_file_exists "$project_path/after-activation-ran" || exit 1

# Assert hooks ran with $2 (new_release_directory)
assert_file_exists "$project_path/releases/1/before-activation-release" || exit 1
assert_file_exists "$project_path/releases/1/after-activation-release" || exit 1

# Assert current symlink exists and points to a release
assert_directory_exists "$project_path/current" || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1

# Assert .env and storage in release are symlinks
assert_symlink "$project_path/releases/1/.env" || exit 1
assert_symlink "$project_path/releases/1/storage" || exit 1
