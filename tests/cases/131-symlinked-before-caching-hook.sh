# Test caching with symlinked before-caching.sh hook shared between two projects

# Init two projects
cd "$world_path/case"
lit init "https://github.com/SjorsO/lit.git" project1 > /dev/null
lit init "https://github.com/SjorsO/lit.git" project2 > /dev/null

project1_path="$world_path/case/project1"
project2_path="$world_path/case/project2"

# Setup both projects
for project_path in "$project1_path" "$project2_path"; do
    echo '' > "$project_path/hooks/before-release.sh"
    echo '' > "$project_path/hooks/after-release.sh"
    echo "APP_KEY=test" > "$project_path/.env"

    cd "$project_path"
    lit enable-git-release-caching > /dev/null
    rm "$project_path/hooks/before-caching.sh"
done

# Create a shared hook outside both projects that verifies all 3 arguments
mkdir -p "$world_path/case/shared-hooks"
cat > "$world_path/case/shared-hooks/before-caching.sh" << 'EOF'
touch "$1/shared-hook-ran"
echo "$2" > "$1/before-caching-arg2"
echo "$3" > "$1/before-caching-arg3"
EOF

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

# Verify before-caching hook received correct $2 (project_base_path) and $3 (lit_base_path)
before_caching_arg2=$(cat "$project1_path/releases/1/before-caching-arg2")
assert_file_content "$project1_path/releases/1/before-caching-arg2" "$project1_path" || exit 1
before_caching_arg3=$(cat "$project1_path/releases/1/before-caching-arg3")
assert_file_exists "$before_caching_arg3/lit.sh" || exit 1

# Deploy project2 - should reuse cache from project1
cd "$project2_path"
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Reusing deployment from cache
Creating "'"$project2_path"'/releases/1" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project2_path"'/releases/1"
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1
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

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/([a-f0-9]\{11\})/(COMMIT)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1
assert_file_missing "$project1_path/releases/2" || exit 1

# Project1 should detect hook changed and rebuild
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/([a-f0-9]\{11\})/(COMMIT)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Cached release found but hook changed, rebuilding...
Cloning repository...
Running "project1/hooks/before-caching.sh"...
Caching release...
Creating "'"$project1_path"'/releases/2" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project1_path"'/releases/2"
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1
assert_file_exists "$project1_path/releases/2/shared-hook-v2" || exit 1

# Project2 reuses the cache that project1 just rebuilt
cd "$project2_path"
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1

# Replace dynamic parts
output=$(echo "$output" | sed 's/(in [0-9]*\.[0-9]*s)/(in X seconds)/g')
output=$(echo "$output" | sed 's/([a-f0-9]\{11\})/(COMMIT)/g')
output=$(echo "$output" | sed 's/[[:space:]]*$//')

expected_output='Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Reusing deployment from cache
Creating "'"$project2_path"'/releases/2" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "'"$project2_path"'/releases/2"
Finished successfully (in X seconds)'
assert_exact_output "$expected_output" "$output" || exit 1
assert_file_exists "$project2_path/releases/2/shared-hook-v2" || exit 1
