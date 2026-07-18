<?php

require __DIR__.'/../test-helpers.php';

// Test bundle deployment when .hash file does not exist

[$statusCode] = lit('init', 'https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/bundle-for-lit-tests-without-hash";

file_put_contents("$projectPath/hooks/before-release.sh", "\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// First deploy - should warn about missing .hash file but still deploy
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = replace_curl_errors(replace_hashes(normalize_output($output)));

assert_same(<<<EXPECTED
Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz.hash"... (in X seconds)
Warning: curl: (CURL_ERROR)
Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz"... (XK in X seconds)
Adding bundle to cache ($worldPath/lit/cached-releases/HASH.tar)
Creating "$projectPath/releases/1" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/1"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_directory_exists("$projectPath/releases/1");
assert_file_exists("$projectPath/releases/1/artisan");
assert_file_exists("$projectPath/releases/1/bootstrap/app.php");

// Second deploy - should still check for .hash, warn, then download to check hash
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = replace_curl_errors(replace_hashes(normalize_output($output)));

assert_same(<<<EXPECTED
Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz.hash"... (in X seconds)
Warning: curl: (CURL_ERROR)
Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz"... (XK in X seconds)
Bundle exists in cache, but using the downloaded bundle instead
Bundle is already deployed (hash: HASH)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)
EXPECTED, $output);

assert_file_missing("$projectPath/releases/2");

// Force deploy - still checks hash file (for cache) but doesn't skip if already deployed
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = replace_curl_errors(replace_hashes(normalize_output($output)));

assert_same(<<<EXPECTED
Checking bundle version from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz.hash"... (in X seconds)
Warning: curl: (CURL_ERROR)
Downloading bundle from "https://watchtower-static.fsn1.your-objectstorage.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz"... (XK in X seconds)
Bundle exists in cache, but using the downloaded bundle instead
Bundle is already deployed (hash: HASH)
Using "--force", redeploying...
Creating "$projectPath/releases/2" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_directory_exists("$projectPath/releases/2");
