# Test that hooks receive correct $1 (project_base_path), $2 (new_release_directory), $3 (lit_base_path),
# and that the before-release and after-release hooks also receive $4 (previous_release_directory)

lit init "https://github.com/SjorsO/lit.git" the-project > /dev/null

project_path="$world_path/case/the-project"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Create hooks that write their arguments to files
cat > "$project_path/hooks/before-release.sh" << 'EOF'
echo "$1" > "$1/before-release-arg1"
echo "$2" > "$1/before-release-arg2"
echo "$3" > "$1/before-release-arg3"
echo "$4" > "$1/before-release-arg4"
EOF

cat > "$project_path/hooks/after-release.sh" << 'EOF'
echo "$1" > "$1/after-release-arg1"
echo "$2" > "$1/after-release-arg2"
echo "$3" > "$1/after-release-arg3"
echo "$4" > "$1/after-release-arg4"
EOF

cat > "$project_path/hooks/on-failure.sh" << 'EOF'
echo "$1" > "$1/on-failure-arg1"
echo "$2" > "$1/on-failure-arg2"
EOF

# Deploy
lit deploy > /dev/null

# Verify before-release hook arguments
assert_file_content "$project_path/before-release-arg1" "$project_path" || exit 1
before_arg2=$(cat "$project_path/before-release-arg2")
assert_string_contains "$before_arg2" "releases/1" || exit 1
before_arg3=$(cat "$project_path/before-release-arg3")
assert_file_exists "$before_arg3/lit.sh" || exit 1
# $4 is the previous release directory, which is empty on the first deploy
assert_file_content "$project_path/before-release-arg4" "" || exit 1

# Verify after-release hook arguments
assert_file_content "$project_path/after-release-arg1" "$project_path" || exit 1
after_arg2=$(cat "$project_path/after-release-arg2")
assert_string_contains "$after_arg2" "releases/1" || exit 1
after_arg3=$(cat "$project_path/after-release-arg3")
assert_file_exists "$after_arg3/lit.sh" || exit 1
# $4 is the previous release directory, which is empty on the first deploy
assert_file_content "$project_path/after-release-arg4" "" || exit 1

# on-failure hook should not have been called (deploy succeeded)
assert_file_missing "$project_path/on-failure-arg1" || exit 1

# Now deploy a second time. The after-release hook records its $4 and then fails, which also lets
# us test the on-failure hook.
echo 'echo "$1" > "$1/on-failure-arg1"; echo "$2" > "$1/on-failure-arg2"' > "$project_path/hooks/on-failure.sh"
echo 'echo "$4" > "$1/after-release-arg4"; exit 1' > "$project_path/hooks/after-release.sh"

set +e
lit deploy --force > /dev/null 2>&1
set -e

# On the second deploy, both hooks receive the previous release directory ("releases/1") as $4, and
# it still exists when the hooks run because pruning of old releases only happens after them. The
# before-release hook still records its arguments (it was not overwritten above).
before_arg4=$(cat "$project_path/before-release-arg4")
assert_string_contains "$before_arg4" "releases/1" || exit 1
assert_directory_exists "$before_arg4" || exit 1

after_arg4=$(cat "$project_path/after-release-arg4")
assert_string_contains "$after_arg4" "releases/1" || exit 1
assert_directory_exists "$after_arg4" || exit 1

# Verify on-failure hook arguments
# $1 should be project_base_path
assert_file_content "$project_path/on-failure-arg1" "$project_path" || exit 1
# $2 should be "true" (was released)
assert_file_content "$project_path/on-failure-arg2" "true" || exit 1
