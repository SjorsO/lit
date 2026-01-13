# Test that Lit uses the Deployer shared directory when it exists

project_path="$world_path/case/deployer-project"

mkdir -p "$project_path"

# Set up a Deployer-style directory structure
mkdir -p "$project_path/shared/storage/"{app/public,framework/{cache/data,sessions,views},logs}
echo "APP_KEY=from-shared" > "$project_path/shared/.env"

# Set up Lit configuration manually (simulating a migration from Deployer)
echo "https://github.com/SjorsO/lit.git" > "$project_path/git-repository-url"
echo "main" > "$project_path/git-branch"
echo "not deployed yet" > "$project_path/git-commit"

mkdir -p "$project_path/hooks"
echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"

mkdir -p "$project_path/releases"

cd "$project_path"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Verify the release was created
assert_directory_exists "$project_path/releases/1" || exit 1

# Verify the .env symlink points to shared/.env
assert_symlink "$project_path/releases/1/.env" || exit 1
env_target=$(readlink "$project_path/releases/1/.env")
assert_string_contains "$env_target" "shared/.env" || exit 1

# Verify the storage symlink points to shared/storage
assert_symlink "$project_path/releases/1/storage" || exit 1
storage_target=$(readlink "$project_path/releases/1/storage")
assert_string_contains "$storage_target" "shared/storage" || exit 1

# Verify the .env content is from the shared directory
env_content=$(cat "$project_path/releases/1/.env")
assert_string_contains "$env_content" "from-shared" || exit 1
