# Test init in a non-zero-downtime Laravel project (git)
project_path="$world_path/case/my-app"
mkdir -p "$project_path"
touch "$project_path/artisan"

cd "$project_path"

set +e
output=$(lit init "https://github.com/SjorsO/lit.git" "." 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

expected_output='Reading "https://github.com/SjorsO/lit.git"... Done!

Current branch set to "main"

Finished initializing "my-app"

Next steps:
- Fill in the ".env" file
- Review these newly created hooks:
  - "hooks/before-release.sh"
  - "hooks/after-release.sh"
  - "hooks/on-failure.sh"

After that, either:
- Run "lit deploy" to deploy the current branch (main)
- Run "lit checkout <branch>" to deploy a different branch

After you have deployed with Lit:
- Update your cron and queue workers to point at "/current/artisan" instead of "/artisan"
- Update your nginx to point at "/current/public/index.php" instead of "/public/index.php"

(Optional) Delete the original Laravel project files, keeping only:
- Directories: current/, hooks/, logs/, releases/, storage/
- Files: .env, git-repository-url, git-branch, git-commit'
assert_exact_output "$expected_output" "$output" || exit 1

# Fill in .env so it doesn't show in next steps
echo "APP_KEY=test" > "$project_path/.env"

# Init again - should now detect it's a zero-downtime project
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

# Test init in a non-zero-downtime Laravel project (bundle)
project_path2="$world_path/case/my-bundle-app"
mkdir -p "$project_path2"
touch "$project_path2/composer.json"
echo "APP_KEY=test" > "$project_path2/.env"

cd "$world_path/case"

set +e
output=$(lit init "https://example.com/releases/my-bundle-app.tar.gz" 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

expected_output='Bundle URL set to "https://example.com/releases/my-bundle-app.tar.gz"

Finished initializing "my-bundle-app"

Next steps:
- cd "my-bundle-app"
- Review these newly created hooks:
  - "hooks/before-release.sh"
  - "hooks/after-release.sh"
  - "hooks/on-failure.sh"

After that, run "lit deploy" to download and deploy the bundle

After you have deployed with Lit:
- Update your cron and queue workers to point at "/current/artisan" instead of "/artisan"
- Update your nginx to point at "/current/public/index.php" instead of "/public/index.php"

(Optional) Delete the original Laravel project files, keeping only:
- Directories: current/, hooks/, releases/, storage/
- Files: .env, bundle-url, bundle-hash'
assert_exact_output "$expected_output" "$output" || exit 1

# Init again - should now detect it's a zero-downtime project
cd "$world_path/case/my-bundle-app"
set +e
output=$(lit init "https://example.com/releases/app.tar.gz" "." 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

expected_output='Changing from bundle URL: https://example.com/releases/my-bundle-app.tar.gz
Bundle URL set to "https://example.com/releases/app.tar.gz"

Finished initializing "my-bundle-app"

Run "lit deploy" to download and deploy the bundle'
assert_exact_output "$expected_output" "$output" || exit 1
