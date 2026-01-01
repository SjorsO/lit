set +e
output=$(lit clone "https://github.com/SjorsO/lit.git" 2>&1)
status_code=$?
set -e

if [ "$status_code" -ne 0 ]; then
    printf 'lit clone failed with status %s:\n%s\n' "$status_code" "$output"
    exit 1
fi

project_path="$world_path/case/lit"

# Assert directories exist
assert_directory_exists "$project_path/lit" || exit 1
assert_directory_exists "$project_path/hooks" || exit 1
assert_directory_exists "$project_path/releases" || exit 1

# Assert lit config files have correct content
assert_file_content "$project_path/lit/source-type" "git" || exit 1
assert_file_content "$project_path/lit/git-repository-url" "https://github.com/SjorsO/lit.git" || exit 1
assert_file_content "$project_path/lit/current-branch" "main" || exit 1
assert_file_content "$project_path/lit/current-commit" "not deployed yet" || exit 1

# Assert hooks are copied from the git stubs
assert_files_match "$project_path/hooks/before-activation.sh" "$world_path/lit/stubs/hooks-for-git/before-activation.sh.stub" || exit 1
assert_files_match "$project_path/hooks/after-activation.sh" "$world_path/lit/stubs/hooks-for-git/after-activation.sh.stub" || exit 1

# Assert .env file exists and is empty
assert_file_exists "$project_path/.env" || exit 1
assert_file_content "$project_path/.env" "" || exit 1

# Assert current symlink doesn't exist yet (created after first deployment)
assert_not_exists "$project_path/current" || exit 1

# Assert before-caching hook isn't created (only created when caching is enabled)
assert_not_exists "$project_path/hooks/before-caching.sh" || exit 1
