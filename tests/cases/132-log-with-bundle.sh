# Test that lit log fails for bundle deployments (no git repo)

lit init "https://watchtower-static.fsn1.your-objectstorage.com/bundle-for-lit-tests.tar.zst" > /dev/null

project_path="$world_path/case/bundle-for-lit-tests"

echo '' > "$project_path/hooks/before-activation.sh"
echo '' > "$project_path/hooks/after-activation.sh"
echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Deploy the bundle
lit deploy > /dev/null

# lit log should fail because bundle releases don't have .git
set +e
output=$(lit log 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "No git repository found" || exit 1
