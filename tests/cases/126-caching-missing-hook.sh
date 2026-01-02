# Test caching when before-caching.sh hook is missing

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo '' > "$project_path/hooks/before-activation.sh"
echo '' > "$project_path/hooks/after-activation.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Enable caching but delete the hook
lit enable-caching > /dev/null
rm "$project_path/hooks/before-caching.sh"

# Deploy should warn about missing hook but still work
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" 'Wanted to run' || exit 1
assert_string_contains "$output" 'hooks/before-caching.sh" but it does not exist' || exit 1
assert_string_not_contains "$output" 'Running "lit/hooks/before-caching.sh"' || exit 1
assert_directory_exists "$project_path/releases/1" || exit 1
