<?php

require __DIR__.'/../test-helpers.php';

// Test bundle deployment reuses cached bundle

[$statusCode] = lit('init', 'https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/bundle-for-lit-tests";

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// First deploy - should download and cache
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst"... (XK in X seconds)
Adding bundle to cache ($worldPath/lit/cached-releases/HASH.tar)
Creating "$projectPath/releases/1" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/1"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_directory_exists("$projectPath/releases/1");

// Verify bundle was cached using the hash from lit.json
$bundleHash = lit_state($projectPath)['bundle_hash'];
$cachedFile = "$worldPath/lit/cached-releases/$bundleHash.tar";

assert_file_exists($cachedFile);

// Assert this is the only file in the cache directory
assert_same(1, count(glob("$worldPath/lit/cached-releases/*")));

// Set the cached file to 3 days old
touch($cachedFile, time() - 3 * 24 * 60 * 60);

// Create a reference file to compare timestamps
touch("$worldPath/timestamp-reference");

// Create a second project with the same bundle URL
chdir("$worldPath/case");

[$statusCode] = lit('init', 'https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst', 'second-project');

assert_same(0, $statusCode);

$secondProjectPath = "$worldPath/case/second-project";

neutralize_hooks($secondProjectPath);
file_put_contents("$secondProjectPath/.env", "APP_KEY=test\n");

chdir($secondProjectPath);

// Second project deploy - should use cache
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Using cached bundle (hash: HASH)
Creating "$secondProjectPath/releases/1" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$secondProjectPath/releases/1"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_directory_exists("$secondProjectPath/releases/1");

// Cache file should have been touched (newer than reference)
clearstatcache();

if (filemtime($cachedFile) < filemtime("$worldPath/timestamp-reference")) {
    printf("Expected cache file to be touched after reuse\n");
    exit(1);
}

// Rename the cached file so it's not a cache hit anymore
rename($cachedFile, "$cachedFile.renamed");

// Deploy without force - should skip because already deployed (hash check passes)
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Bundle is already deployed (hash: HASH)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)
EXPECTED, $output);

assert_file_missing("$secondProjectPath/releases/2");

// Force deploy - should re-download since cache is gone
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst"... (XK in X seconds)
Adding bundle to cache ($worldPath/lit/cached-releases/HASH.tar)
Bundle is already deployed (hash: HASH)
Using "--force", redeploying...
Creating "$secondProjectPath/releases/2" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$secondProjectPath/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_directory_exists("$secondProjectPath/releases/2");
