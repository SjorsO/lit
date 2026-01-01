set +e
output=$(lit clone "https://example.com/releases/my-app.tar.gz" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

project_path="$world_path/case/my-app"

# Assert directories exist
assert_directory_exists "$project_path/lit" || exit 1
assert_directory_exists "$project_path/hooks" || exit 1
assert_directory_exists "$project_path/releases" || exit 1

# Assert lit config files have correct content
assert_file_content "$project_path/lit/source-type" "bundle" || exit 1
assert_file_content "$project_path/lit/bundle-url" "https://example.com/releases/my-app.tar.gz" || exit 1
assert_file_content "$project_path/lit/current-bundle-hash" "not deployed yet" || exit 1

# Assert hooks are copied from the bundle stubs
assert_files_match "$project_path/hooks/before-activation.sh" "$world_path/lit/stubs/hooks-for-bundle/before-activation.sh.stub" || exit 1
assert_files_match "$project_path/hooks/after-activation.sh" "$world_path/lit/stubs/hooks-for-bundle/after-activation.sh.stub" || exit 1

# Assert .env file exists and is empty
assert_file_exists "$project_path/.env" || exit 1
assert_file_content "$project_path/.env" "" || exit 1

# Assert current symlink doesn't exist yet (created after first deployment)
assert_file_missing "$project_path/current" || exit 1

# Assert before-caching hook isn't created (caching is only for git)
assert_file_missing "$project_path/hooks/before-caching.sh" || exit 1

# Assert git files don't exist
assert_file_missing "$project_path/lit/git-repository-url" || exit 1
assert_file_missing "$project_path/lit/current-branch" || exit 1
assert_file_missing "$project_path/lit/current-commit" || exit 1
