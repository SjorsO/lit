# Test that lock directory is properly cleaned up after successful command

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo '' > "$project_path/hooks/before-release.sh"
echo '' > "$project_path/hooks/after-release.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Lock should not exist before deploy
assert_file_missing "$project_path/lit/lit-is-currently-running" || exit 1

# Deploy should succeed
lit deploy > /dev/null

# Lock should be cleaned up after deploy
assert_file_missing "$project_path/lit/lit-is-currently-running" || exit 1

# Run another command to verify lock works correctly
lit status > /dev/null

# Lock should still not exist
assert_file_missing "$project_path/lit/lit-is-currently-running" || exit 1
