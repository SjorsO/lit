project_path="$world_path/case/my-app"

# First bundle init
set +e
output=$(lit init "https://example.com/releases/my-app.tar.gz" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

expected_output='Bundle URL set to "https://example.com/releases/my-app.tar.gz"

Finished initializing "my-app"

Next steps:
- cd "my-app"
- Fill in the ".env" file
- Review these newly created hooks:
  - "hooks/before-release.sh"
  - "hooks/after-release.sh"
  - "hooks/on-failure.sh"

After that, run "lit deploy" to download and deploy the bundle'
assert_exact_output "$expected_output" "$output" || exit 1

# Fill in .env so it doesn't show in next steps
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Second bundle init (different URL, same project)
set +e
output=$(lit init "https://example.com/releases/my-app-v2.tar.gz" "." 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

expected_output='Changing from bundle URL: https://example.com/releases/my-app.tar.gz
Bundle URL set to "https://example.com/releases/my-app-v2.tar.gz"

Finished initializing "my-app"

Run "lit deploy" to download and deploy the bundle'
assert_exact_output "$expected_output" "$output" || exit 1

# Switch to git
set +e
output=$(lit init "https://github.com/SjorsO/lit.git" "." 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

expected_output='Reading "https://github.com/SjorsO/lit.git"... Done!

Changing from bundle URL: https://example.com/releases/my-app-v2.tar.gz
Current branch set to "main"

Finished initializing "my-app"

Next steps:
- Review these hooks:
  - "hooks/before-release.sh"
  - "hooks/after-release.sh"
  - "hooks/on-failure.sh"

After that, either:
- Run "lit deploy" to deploy the current branch (main)
- Run "lit checkout <branch>" to deploy a different branch'
assert_exact_output "$expected_output" "$output" || exit 1

# Assert bundle files were deleted
assert_file_missing "$project_path/bundle-url" || exit 1
assert_file_missing "$project_path/bundle-hash" || exit 1

# Another git init (same source type)
set +e
output=$(lit init "https://github.com/SjorsO/lit.git" "." 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

expected_output='Reading "https://github.com/SjorsO/lit.git"... Done!

Changing from git repository URL: https://github.com/SjorsO/lit.git
Current branch set to "main"

Finished initializing "my-app"

Run "lit deploy" to deploy the current branch (main)
Run "lit checkout <branch>" to deploy a different branch'
assert_exact_output "$expected_output" "$output" || exit 1

# Delete git-branch file to test "no branch" fallback
rm "$project_path/git-branch"

# Switch back to bundle
set +e
output=$(lit init "https://example.com/final-bundle.tar" "." 2>&1)
status_code=$?
set -e
assert_same 0 "$status_code" || exit 1

expected_output='Changing from git URL: https://github.com/SjorsO/lit.git (branch: no branch)
Bundle URL set to "https://example.com/final-bundle.tar"

Finished initializing "my-app"

Next steps:
- Review these hooks:
  - "hooks/before-release.sh"
  - "hooks/after-release.sh"
  - "hooks/on-failure.sh"

After that, run "lit deploy" to download and deploy the bundle'
assert_exact_output "$expected_output" "$output" || exit 1

# Assert git files were deleted
assert_file_missing "$project_path/git-repository-url" || exit 1
assert_file_missing "$project_path/git-branch" || exit 1
assert_file_missing "$project_path/git-commit" || exit 1

# Delete a hook, then init again - should recreate it and show message
rm "$project_path/hooks/before-release.sh"

set +e
output=$(lit init "https://example.com/another-bundle.tar" "." 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_file_exists "$project_path/hooks/before-release.sh" || exit 1

expected_output='Changing from bundle URL: https://example.com/final-bundle.tar
Bundle URL set to "https://example.com/another-bundle.tar"

Finished initializing "my-app"

Next steps:
- Review these newly created hooks:
  - "hooks/before-release.sh"

After that, run "lit deploy" to download and deploy the bundle'
assert_exact_output "$expected_output" "$output" || exit 1
