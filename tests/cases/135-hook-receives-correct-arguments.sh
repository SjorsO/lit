# Test that hooks receive correct $1 (project_base_path) and $2 (new_release_directory)

lit init "https://github.com/SjorsO/lit.git" the-project > /dev/null

project_path="$world_path/case/the-project"

echo "APP_KEY=test" > "$project_path/.env"

cd "$project_path"

# Create hooks that write their arguments to files
cat > "$project_path/hooks/before-activation.sh" << 'EOF'
echo "$1" > "$1/before-activation-arg1"
echo "$2" > "$1/before-activation-arg2"
EOF

cat > "$project_path/hooks/after-activation.sh" << 'EOF'
echo "$1" > "$1/after-activation-arg1"
echo "$2" > "$1/after-activation-arg2"
EOF

cat > "$project_path/hooks/on-failure.sh" << 'EOF'
echo "$1" > "$1/on-failure-arg1"
echo "$2" > "$1/on-failure-arg2"
EOF

# Deploy
lit deploy > /dev/null

# Verify before-activation hook arguments
assert_file_content "$project_path/before-activation-arg1" "$project_path" || exit 1
before_arg2=$(cat "$project_path/before-activation-arg2")
assert_string_contains "$before_arg2" "releases/1" || exit 1

# Verify after-activation hook arguments
assert_file_content "$project_path/after-activation-arg1" "$project_path" || exit 1
after_arg2=$(cat "$project_path/after-activation-arg2")
assert_string_contains "$after_arg2" "releases/1" || exit 1

# on-failure hook should not have been called (deploy succeeded)
assert_file_missing "$project_path/on-failure-arg1" || exit 1

# Now test on-failure hook by making after-activation fail
echo 'echo "$1" > "$1/on-failure-arg1"; echo "$2" > "$1/on-failure-arg2"' > "$project_path/hooks/on-failure.sh"
echo 'exit 1' > "$project_path/hooks/after-activation.sh"

set +e
lit deploy --force > /dev/null 2>&1
set -e

# Verify on-failure hook arguments
# $1 should be project_base_path
assert_file_content "$project_path/on-failure-arg1" "$project_path" || exit 1
# $2 should be "true" (release was activated)
assert_file_content "$project_path/on-failure-arg2" "true" || exit 1
