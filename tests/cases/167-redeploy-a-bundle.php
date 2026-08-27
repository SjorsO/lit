<?php

require __DIR__.'/../test-helpers.php';

// Test the lit redeploy command for bundles

[$statusCode] = lit('init', 'https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/bundle-for-lit-tests";

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// Redeploy before any deploy should fail
[$statusCode, $output] = lit('redeploy');

assert_same(1, $statusCode);
assert_same('Nothing is deployed yet, run "lit deploy" first', $output);

// Deploy the bundle
[$statusCode] = lit('deploy');

assert_same(0, $statusCode);
assert_directory_exists("$projectPath/releases/1");

$bundleHash = lit_state($projectPath)['bundle_hash'];

// 1. Redeploy with the bundle in cache - no remote check needed
[$statusCode, $output] = lit('redeploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Redeploying the current bundle (hash: HASH)
Using cached bundle (hash: HASH)
Creating "$projectPath/releases/2" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_string_contains(readlink("$projectPath/current"), 'releases/2');
assert_lit_state_value($projectPath, 'bundle_hash', $bundleHash);

// 2. Redeploy with the cache evicted - the hash file confirms the remote
// still has the same bundle, so it is downloaded again
array_map('unlink', glob("$worldPath/lit/cached-releases/*.tar"));

[$statusCode, $output] = lit('redeploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Redeploying the current bundle (hash: HASH)
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst"... (XK in X seconds)
Adding bundle to cache ($worldPath/lit/cached-releases/HASH.tar)
Creating "$projectPath/releases/3" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/3"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_lit_state_value($projectPath, 'bundle_hash', $bundleHash);

// 3. The remote bundle changed - redeploy must fail
// Simulate this by changing the deployed hash in lit.json
array_map('unlink', glob("$worldPath/lit/cached-releases/*.tar"));

set_lit_state_value($projectPath, 'bundle_hash', str_repeat('a', 40));

[$statusCode, $output] = lit('redeploy');

assert_same(1, $statusCode);

$output = normalize_output($output);

assert_same(<<<'EXPECTED'
Redeploying the current bundle (hash: HASH)
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
The remote bundle (hash: HASH) does not match the deployed bundle (hash: HASH)
Cannot redeploy the exact same bundle
Finished with errors (in X seconds)
EXPECTED, $output);

assert_file_missing("$projectPath/releases/4");

// Now a bundle without a .hash file on the remote
chdir("$worldPath/case");

[$statusCode] = lit('init', 'https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz');

assert_same(0, $statusCode);

$project2Path = "$worldPath/case/bundle-for-lit-tests-without-hash";

neutralize_hooks($project2Path);
file_put_contents("$project2Path/.env", "APP_KEY=test\n");

chdir($project2Path);

[$statusCode] = lit('deploy');

assert_same(0, $statusCode);

// 4. No hash file - the full bundle is downloaded and its hash is checked
array_map('unlink', glob("$worldPath/lit/cached-releases/*.tar"));

[$statusCode, $output] = lit('redeploy');

assert_same(0, $statusCode);

$output = replace_curl_errors(normalize_output($output));

assert_same(<<<EXPECTED
Redeploying the current bundle (hash: HASH)
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz.hash"... (in X seconds)
Warning: curl: (CURL_ERROR)
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz"... (XK in X seconds)
Adding bundle to cache ($worldPath/lit/cached-releases/HASH.tar)
Creating "$project2Path/releases/2" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$project2Path/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);

// 5. No hash file and the downloaded bundle doesn't match - redeploy must fail
array_map('unlink', glob("$worldPath/lit/cached-releases/*.tar"));

set_lit_state_value($project2Path, 'bundle_hash', str_repeat('a', 40));

[$statusCode, $output] = lit('redeploy');

assert_same(1, $statusCode);

$output = replace_curl_errors(normalize_output($output));

assert_same(<<<'EXPECTED'
Redeploying the current bundle (hash: HASH)
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz.hash"... (in X seconds)
Warning: curl: (CURL_ERROR)
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-without-hash.tar.gz"... (XK in X seconds)
The downloaded bundle (hash: HASH) does not match the deployed bundle (hash: HASH)
Cannot redeploy the exact same bundle
Finished with errors (in X seconds)
EXPECTED, $output);

assert_file_missing("$project2Path/releases/3");

// The temporary bundle file is cleaned up after the failure
assert_file_missing("$project2Path/bundle-for-current-deployment.tar");
