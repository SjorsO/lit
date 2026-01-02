# Override curl in flush-opcache.sh to simulate success without making HTTP requests
{
    echo 'curl() { printf "OPCache flushed successfully (simulated).\n"; }'
    cat "$world_path/lit/scripts/flush-opcache.sh"
} > "$world_path/lit/scripts/flush-opcache.sh.tmp"
mv "$world_path/lit/scripts/flush-opcache.sh.tmp" "$world_path/lit/scripts/flush-opcache.sh"

lit init "https://github.com/SjorsO/lit.git" > /dev/null

project_path="$world_path/case/lit"

cd "$project_path"

echo "APP_URL=https://example.com/" > "$project_path/.env"

# Set up hooks
cat << 'HOOK' > "$project_path/hooks/before-release.sh"
# no-op
HOOK

cat << 'HOOK' > "$project_path/hooks/after-release.sh"
project_base_directory="$1"
new_release_directory="$2"
lit_base_path="$3"

mkdir -p "$new_release_directory/public"

(cd "$project_base_directory" && bash "$lit_base_path/lit.sh" flush-opcache)
HOOK

# First deploy (flush-opcache will say "first deployment" which is correct)
set +e
output=$(lit deploy 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" "Not flushing opcache because this appears to be the first deployment" || exit 1

# Create public directory for release 1 (in case hook ran before we could)
mkdir -p "$project_path/releases/1/public"

# Second deploy triggers flush-opcache via hook
set +e
output=$(lit deploy --force 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" 'Pinging "https://example.com" to flush OPCache.' || exit 1
assert_string_contains "$output" "OPCache flushed successfully (simulated)." || exit 1

# Verify the temporary PHP files were cleaned up
php_files_in_current=$(find "$project_path/current/public" -name "lit-flush-opcache-*.php" 2>/dev/null | wc -l | tr -d ' ')
php_files_in_release1=$(find "$project_path/releases/1/public" -name "lit-flush-opcache-*.php" 2>/dev/null | wc -l | tr -d ' ')

assert_same "0" "$php_files_in_current" || exit 1
assert_same "0" "$php_files_in_release1" || exit 1

# Test APP_URL with single quotes
echo "APP_URL='https://single-quoted.com'" > "$project_path/.env"

set +e
output=$(lit flush-opcache 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" 'Pinging "https://single-quoted.com" to flush OPCache.' || exit 1

# Test APP_URL with double quotes
echo 'APP_URL="https://double-quoted.com"' > "$project_path/.env"

set +e
output=$(lit flush-opcache 2>&1)
status_code=$?
set -e

assert_same 0 "$status_code" || exit 1
assert_string_contains "$output" 'Pinging "https://double-quoted.com" to flush OPCache.' || exit 1

# Test error cases

# Missing APP_URL
echo "APP_KEY=test" > "$project_path/.env"

set +e
output=$(lit flush-opcache 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "Unable to flush opcache, APP_URL not found in .env file" || exit 1

# Missing .env file
rm "$project_path/.env"

set +e
output=$(lit flush-opcache 2>&1)
status_code=$?
set -e

assert_same 1 "$status_code" || exit 1
assert_string_contains "$output" "Unable to flush opcache, no .env file found" || exit 1
