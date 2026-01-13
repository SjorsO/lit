# Test that storage directory structure is created correctly

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

assert_directory_exists "$project_path/storage" || exit 1
assert_directory_exists "$project_path/storage/app/public" || exit 1
assert_directory_exists "$project_path/storage/app/private" || exit 1
assert_directory_exists "$project_path/storage/framework/cache/data" || exit 1
assert_directory_exists "$project_path/storage/framework/sessions" || exit 1
assert_directory_exists "$project_path/storage/framework/views" || exit 1
assert_directory_exists "$project_path/storage/logs" || exit 1

echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Deploy should create the storage directory if missing
lit deploy > /dev/null

# Release should have symlink to storage
assert_symlink "$project_path/releases/1/storage" || exit 1
storage_target=$(readlink "$project_path/releases/1/storage")
assert_string_contains "$storage_target" "storage" || exit 1
