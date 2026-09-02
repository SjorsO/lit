<?php

require __DIR__.'/../test-helpers.php';

// Test bundle deployment when .hash file contains wrong hash
// The .hash file contains "1234567890000000000000000000000000000000" which doesn't match the actual bundle
// The actual bundle hash is "95632a16d315752fc2c8e5b298546b389094a9d2".

[$statusCode] = lit('init', 'https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/bundle-for-lit-tests-with-wrong-hash";

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// First deploy - should warn about wrong hash but still deploy
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output, preserveHashes: true);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar.hash"... (in X seconds)
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar"... (XK in X seconds)
Warning: the hash from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar.hash" does not match the actual hash from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar"
Warning: actual bundle hash "95632a16d315752fc2c8e5b298546b389094a9d2", hash from hash file "1234567890000000000000000000000000000000"
Adding bundle to cache ($worldPath/lit/cached-releases/95632a16d315752fc2c8e5b298546b389094a9d2.tar)
Creating "$projectPath/releases/1" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/1"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_directory_exists("$projectPath/releases/1");

// The stored hash should be the ACTUAL bundle hash, not the wrong hash from the .hash file
assert_lit_state_value($projectPath, 'bundle_hash', '95632a16d315752fc2c8e5b298546b389094a9d2');

// Second deploy - should download (wrong hash means no cache hit) but not deploy (because actual hash matches)
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output, preserveHashes: true);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar.hash"... (in X seconds)
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar"... (XK in X seconds)
Warning: the hash from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar.hash" does not match the actual hash from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar"
Warning: actual bundle hash "95632a16d315752fc2c8e5b298546b389094a9d2", hash from hash file "1234567890000000000000000000000000000000"
Bundle exists in cache, but using the downloaded bundle instead
Bundle is already deployed (hash: 95632a16d315752fc2c8e5b298546b389094a9d2)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)
EXPECTED, $output);

assert_file_missing("$projectPath/releases/2");

// Force deploy - still checks hash file (for cache) but doesn't skip if already deployed
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = normalize_output($output, preserveHashes: true);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar.hash"... (in X seconds)
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar"... (XK in X seconds)
Warning: the hash from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar.hash" does not match the actual hash from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-wrong-hash.tar"
Warning: actual bundle hash "95632a16d315752fc2c8e5b298546b389094a9d2", hash from hash file "1234567890000000000000000000000000000000"
Bundle exists in cache, but using the downloaded bundle instead
Bundle is already deployed (hash: 95632a16d315752fc2c8e5b298546b389094a9d2)
Using "--force", redeploying...
Creating "$projectPath/releases/2" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_directory_exists("$projectPath/releases/2");
