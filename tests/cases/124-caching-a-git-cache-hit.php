<?php

require __DIR__.'/../test-helpers.php';

// Test deploy with caching - cache hit scenario (reusing cached release)

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/lit";

// Hooks that create marker files to verify they run even with cached releases
file_put_contents("$projectPath/hooks/before-release.sh", 'touch "$2/before-release-ran"'."\n");
file_put_contents("$projectPath/hooks/after-release.sh", 'touch "$2/after-release-ran"'."\n");
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// Enable caching with a hook that creates a file with a random value
[$statusCode] = lit('enable-git-release-caching');

assert_same(0, $statusCode);

file_put_contents("$projectPath/hooks/before-caching.sh", 'uuidgen > "$1/cache-marker"'."\n");

// Deploy main - should build and cache
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Cloning repository...
Running "lit/hooks/before-caching.sh"...
Caching release...
Creating "$projectPath/releases/1" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/1"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_directory_exists("$projectPath/releases/1");
assert_file_exists("$projectPath/releases/1/cache-marker");

// Save the main branch cache marker value and cache file path
$mainCacheMarker = file_get_contents("$projectPath/releases/1/cache-marker");
$mainCacheFile = glob("$worldPath/lit/cached-releases/*.tar")[0];

// Checkout this-branch-is-used-in-unit-tests - should build and cache for that branch
[$statusCode, $output] = lit('checkout', 'this-branch-is-used-in-unit-tests');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "this-branch-is-used-in-unit-tests"...
Cloning repository...
Running "lit/hooks/before-caching.sh"...
Caching release...
Creating "$projectPath/releases/2" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_directory_exists("$projectPath/releases/2");
assert_file_exists("$projectPath/releases/2/cache-marker");

// Save the this-branch-is-used-in-unit-tests cache marker value (should be different from main)
$otherBranchCacheMarker = file_get_contents("$projectPath/releases/2/cache-marker");

if ($mainCacheMarker === $otherBranchCacheMarker) {
    printf("Expected different cache markers for different branches\n");
    exit(1);
}

// Set the main branch cache file to 3 days old
touch($mainCacheFile, time() - 3 * 24 * 60 * 60);

// Create a reference file to compare timestamps
touch("$worldPath/timestamp-reference");

// Checkout main again - should use cache with same marker value
[$statusCode, $output] = lit('checkout', 'main');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "main"...
Reusing deployment from cache
Creating "$projectPath/releases/3" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/3"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_directory_exists("$projectPath/releases/3");
assert_file_content("$projectPath/releases/3/cache-marker", rtrim($mainCacheMarker, "\n"));

// Cache file should have been touched (newer than or equal to reference)
clearstatcache();

if (filemtime($mainCacheFile) < filemtime("$worldPath/timestamp-reference")) {
    printf("Expected cache file to be touched after reuse\n");
    exit(1);
}

// Verify before/after release hooks still run with cached releases
assert_file_exists("$projectPath/releases/3/before-release-ran");
assert_file_exists("$projectPath/releases/3/after-release-ran");

// Checkout this-branch-is-used-in-unit-tests again - should use cache with same marker value
[$statusCode, $output] = lit('checkout', 'this-branch-is-used-in-unit-tests');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "this-branch-is-used-in-unit-tests"...
Reusing deployment from cache
Creating "$projectPath/releases/4" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/4"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_directory_exists("$projectPath/releases/4");
assert_file_content("$projectPath/releases/4/cache-marker", rtrim($otherBranchCacheMarker, "\n"));

// Verify before/after release hooks still run with cached releases
assert_file_exists("$projectPath/releases/4/before-release-ran");
assert_file_exists("$projectPath/releases/4/after-release-ran");

// Force deploy - should use cache with same marker value
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "this-branch-is-used-in-unit-tests" of "https://github.com/SjorsO/lit.git"...
Latest commit of "this-branch-is-used-in-unit-tests" is already deployed (COMMIT)
Using "--force", redeploying...
Reusing deployment from cache
Creating "$projectPath/releases/5" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/5"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_file_content("$projectPath/current/cache-marker", rtrim($otherBranchCacheMarker, "\n"));

// Verify before/after release hooks still run with cached releases
assert_file_exists("$projectPath/current/before-release-ran");
assert_file_exists("$projectPath/current/after-release-ran");
