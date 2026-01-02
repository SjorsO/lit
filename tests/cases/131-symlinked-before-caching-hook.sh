# Test caching with symlinked before-caching.sh hook shared between two projects

# Init two projects
cd "$world_path/case"
lit init "https://github.com/SjorsO/lit.git" project1 > /dev/null
lit init "https://github.com/SjorsO/lit.git" project2 > /dev/null

project1_path="$world_path/case/project1"
project2_path="$world_path/case/project2"

# Setup both projects
for project_path in "$project1_path" "$project2_path"; do
    echo '' > "$project_path/hooks/before-activation.sh"
    echo '' > "$project_path/hooks/after-activation.sh"
    echo "APP_KEY=test" > "$project_path/.env"

    cd "$project_path"
    lit enable-caching > /dev/null
    rm "$project_path/hooks/before-caching.sh"
done

# Create a shared hook outside both projects
mkdir -p "$world_path/case/shared-hooks"
echo 'touch "$1/shared-hook-ran"' > "$world_path/case/shared-hooks/before-caching.sh"

# Symlink the shared hook to both projects
ln -s "$world_path/case/shared-hooks/before-caching.sh" "$project1_path/hooks/before-caching.sh"
ln -s "$world_path/case/shared-hooks/before-caching.sh" "$project2_path/hooks/before-caching.sh"

# Deploy project1
cd "$project1_path"
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_file_exists "$project1_path/releases/1/shared-hook-ran" || exit 1

# Deploy project2 - should reuse cache from project1
cd "$project2_path"
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Reusing deployment from cache" || exit 1
assert_file_exists "$project2_path/releases/1/shared-hook-ran" || exit 1

# Changing the shared hook should invalidate cache
echo 'touch "$1/shared-hook-v2"' > "$world_path/case/shared-hooks/before-caching.sh"

# Without --force, deploy is skipped even if hook changed
cd "$project1_path"
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "is already deployed" || exit 1
assert_file_missing "$project1_path/releases/2" || exit 1

# Project1 should detect hook changed and rebuild
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Cached release found but hook changed, rebuilding" || exit 1
assert_file_exists "$project1_path/releases/2/shared-hook-v2" || exit 1

# Project2 reuses the cache that project1 just rebuilt
cd "$project2_path"
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Reusing deployment from cache" || exit 1
assert_file_exists "$project2_path/releases/2/shared-hook-v2" || exit 1
