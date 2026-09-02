<?php

require __DIR__.'/../test-helpers.php';

// Test bundle deployment when .hash file contains garbage (not a valid SHA1)
// The garbage data contains "fat little body" and some other stuff.

[$statusCode] = lit('init', 'https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-garbage-hash.tar');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/bundle-for-lit-tests-with-garbage-hash";

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// First deploy - should warn about invalid hash format but still deploy
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output, preserveHashes: true);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-garbage-hash.tar.hash"... (in X seconds)
Warning: "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-garbage-hash.tar.hash" does not contain a valid SHA1 hash
Hash file contents: According to all known laws of aviation, there is no way a bee should be able to fly.
Its wings are too small to get its fat little body off the ground.
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-garbage-hash.tar"... (XK in X seconds)
Adding bundle to cache ($worldPath/lit/cached-releases/7c5f5a0f3460dda3e83c9397ba66e7fd0c21dd6b.tar)
Creating "$projectPath/releases/1" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/1"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_directory_exists("$projectPath/releases/1");

// Second deploy - should skip because actual hash matches (garbage hash is ignored)
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output, preserveHashes: true);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-garbage-hash.tar.hash"... (in X seconds)
Warning: "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-garbage-hash.tar.hash" does not contain a valid SHA1 hash
Hash file contents: According to all known laws of aviation, there is no way a bee should be able to fly.
Its wings are too small to get its fat little body off the ground.
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-garbage-hash.tar"... (XK in X seconds)
Bundle exists in cache, but using the downloaded bundle instead
Bundle is already deployed (hash: 7c5f5a0f3460dda3e83c9397ba66e7fd0c21dd6b)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)
EXPECTED, $output);

assert_file_missing("$projectPath/releases/2");

// Force deploy - should redeploy
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = normalize_output($output, preserveHashes: true);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-garbage-hash.tar.hash"... (in X seconds)
Warning: "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-garbage-hash.tar.hash" does not contain a valid SHA1 hash
Hash file contents: According to all known laws of aviation, there is no way a bee should be able to fly.
Its wings are too small to get its fat little body off the ground.
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests-with-garbage-hash.tar"... (XK in X seconds)
Bundle exists in cache, but using the downloaded bundle instead
Bundle is already deployed (hash: 7c5f5a0f3460dda3e83c9397ba66e7fd0c21dd6b)
Using "--force", redeploying...
Creating "$projectPath/releases/2" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_directory_exists("$projectPath/releases/2");
