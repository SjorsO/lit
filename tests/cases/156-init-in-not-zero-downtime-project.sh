# Test that lit init fails in directories that aren't zero-downtime projects

# Test 1: Directory with artisan file (Laravel project without zero-downtime structure)
mkdir -p "$world_path/case/laravel-with-artisan"
touch "$world_path/case/laravel-with-artisan/artisan"

set +e
output=$(lit init "https://example.com/bundle.tar.gz" "laravel-with-artisan" 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1

expected_output='Directory "laravel-with-artisan" contains a Laravel project without zero-downtime structure
Lit can only be initialized in projects that already have zero-downtime structure.

For migration instructions, see: https://github.com/SjorsO/lit?tab=readme-ov-file#migrating-an-existing-project'
assert_exact_output "$expected_output" "$output" || exit 1

# Test 2: Directory with composer.json (Laravel project without zero-downtime structure)
mkdir -p "$world_path/case/laravel-with-composer"
echo '{"name": "laravel/laravel"}' > "$world_path/case/laravel-with-composer/composer.json"

set +e
output=$(lit init "https://example.com/bundle.tar.gz" "laravel-with-composer" 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1

expected_output='Directory "laravel-with-composer" contains a Laravel project without zero-downtime structure
Lit can only be initialized in projects that already have zero-downtime structure.

For migration instructions, see: https://github.com/SjorsO/lit?tab=readme-ov-file#migrating-an-existing-project'
assert_exact_output "$expected_output" "$output" || exit 1

# Test 3: Using "." in current directory with artisan file
mkdir -p "$world_path/case/laravel-current-dir"
touch "$world_path/case/laravel-current-dir/artisan"
cd "$world_path/case/laravel-current-dir"

set +e
output=$(lit init "https://example.com/bundle.tar.gz" "." 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1

expected_output='Current directory contains a Laravel project without zero-downtime structure
Lit can only be initialized in projects that already have zero-downtime structure.

For migration instructions, see: https://github.com/SjorsO/lit?tab=readme-ov-file#migrating-an-existing-project'
assert_exact_output "$expected_output" "$output" || exit 1

# Test 4: Directory with random files (not a Laravel project, not zero-downtime)
cd "$world_path/case"
mkdir -p "$world_path/case/random-files"
echo "some content" > "$world_path/case/random-files/file.txt"

set +e
output=$(lit init "https://example.com/bundle.tar.gz" "random-files" 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1

expected_output='Directory "random-files" already exists and is not a Laravel project'
assert_exact_output "$expected_output" "$output" || exit 1

# Test 5: Using "." in current directory with random files
cd "$world_path/case/random-files"

set +e
output=$(lit init "https://example.com/bundle.tar.gz" "." 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1

expected_output='Directory "random-files" already exists and is not a Laravel project'
assert_exact_output "$expected_output" "$output" || exit 1
