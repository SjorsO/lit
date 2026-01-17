# Test that symlinked hooks work and are not deleted/overwritten by lit init

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

echo "APP_KEY=test" > "$project_path/.env"

# Remove the default hooks created by init
rm "$project_path/hooks/before-release.sh"
rm "$project_path/hooks/after-release.sh"
rm "$project_path/hooks/on-failure.sh"

# Create shared hooks directory outside the project
mkdir -p "$world_path/case/shared-hooks"

cat > "$world_path/case/shared-hooks/before-release.sh" << 'EOF'
touch "$1/before-release-ran"
EOF

cat > "$world_path/case/shared-hooks/after-release.sh" << 'EOF'
touch "$1/after-release-ran"
EOF

cat > "$world_path/case/shared-hooks/on-failure.sh" << 'EOF'
touch "$1/on-failure-ran"
EOF

# Symlink all hooks
ln -s "$world_path/case/shared-hooks/before-release.sh" "$project_path/hooks/before-release.sh"
ln -s "$world_path/case/shared-hooks/after-release.sh" "$project_path/hooks/after-release.sh"
ln -s "$world_path/case/shared-hooks/on-failure.sh" "$project_path/hooks/on-failure.sh"

# Verify they are symlinks
assert_symlink "$project_path/hooks/before-release.sh" || exit 1
assert_symlink "$project_path/hooks/after-release.sh" || exit 1
assert_symlink "$project_path/hooks/on-failure.sh" || exit 1

# Deploy and verify symlinked hooks run
cd "$project_path"

set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_file_exists "$project_path/before-release-ran" || exit 1
assert_file_exists "$project_path/after-release-ran" || exit 1
# on-failure should NOT have run (deploy succeeded)
assert_file_missing "$project_path/on-failure-ran" || exit 1

# Record the symlink targets before re-init
before_release_target=$(readlink "$project_path/hooks/before-release.sh")
after_release_target=$(readlink "$project_path/hooks/after-release.sh")
on_failure_target=$(readlink "$project_path/hooks/on-failure.sh")

# Re-run lit init on the same project (simulating updating the URL or re-initializing)
cd "$world_path/case"
set +e
output=$(lit init "https://github.com/SjorsO/lit.git" lit 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Assert symlinks still exist and point to the same targets
assert_symlink "$project_path/hooks/before-release.sh" || exit 1
assert_symlink "$project_path/hooks/after-release.sh" || exit 1
assert_symlink "$project_path/hooks/on-failure.sh" || exit 1

assert_same "$before_release_target" "$(readlink "$project_path/hooks/before-release.sh")" || exit 1
assert_same "$after_release_target" "$(readlink "$project_path/hooks/after-release.sh")" || exit 1
assert_same "$on_failure_target" "$(readlink "$project_path/hooks/on-failure.sh")" || exit 1

# Clean up marker files and deploy again to verify hooks still work after re-init
rm "$project_path/before-release-ran"
rm "$project_path/after-release-ran"

cd "$project_path"

set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_file_exists "$project_path/before-release-ran" || exit 1
assert_file_exists "$project_path/after-release-ran" || exit 1

# Test that on-failure hook also works as a symlink by making after-release fail
echo 'exit 1' > "$world_path/case/shared-hooks/after-release.sh"

# Clean up marker files
rm "$project_path/before-release-ran"
rm "$project_path/after-release-ran"

set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_file_exists "$project_path/before-release-ran" || exit 1
assert_file_exists "$project_path/on-failure-ran" || exit 1
