<?php

require __DIR__.'/../test-helpers.php';

[$statusCode] = lit('init', 'https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/bundle-for-lit-tests";

// Replace hooks to verify $1 and $2 are correct directories
// $1 (project_base_directory) should contain storage/ and logs/
// $2 (new_release_directory) should be a directory with the extracted bundle
file_put_contents("$projectPath/hooks/before-release.sh", '[ -d "$1/storage" ] && [ -d "$1/logs" ] && [ -d "$2" ] && touch "$1/before-release-ran" && touch "$2/before-release-release"'."\n");
file_put_contents("$projectPath/hooks/after-release.sh", '[ -d "$1/storage" ] && [ -d "$1/logs" ] && [ -d "$2" ] && touch "$1/after-release-ran" && touch "$2/after-release-release"'."\n");

chdir($projectPath);

// First deploy with empty .env should fail
[$statusCode, $output] = lit('deploy');

assert_same(1, $statusCode);
assert_same('Your ".env" file is empty, try again when you have filled it in', $output);
assert_file_missing("$projectPath/current");

// Fill in .env and deploy again
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

// Add a stray git caching key (should be removed during bundle deploy)
set_lit_state_value($projectPath, 'git_release_caching_enabled', true);

// Clear bundle cache to ensure we test the download path
array_map('unlink', glob("$worldPath/lit/cached-releases/*.tar"));

[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

// Assert timing formats are correct
// Hash check: "(in X.XX seconds)" format
assert_matches('/\(in [0-9]+\.[0-9]{2} seconds\)/', $output);
// Download: "(XXK in X.XX seconds)" format
assert_matches('/\([0-9]+K in [0-9]+\.[0-9]{2} seconds\)/', $output);
// Final runtime: "(in X.XXs)" format
assert_matches('/\(in [0-9]+\.[0-9]{2}s\)/', $output);

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

// The stray git caching key should be removed during bundle deploy
assert_lit_state_missing($projectPath, 'git_release_caching_enabled');

// Assert hooks ran with $1 (project_base_directory)
assert_file_exists("$projectPath/before-release-ran");
assert_file_exists("$projectPath/after-release-ran");

// Assert hooks ran with $2 (new_release_directory)
assert_file_exists("$projectPath/releases/1/before-release-release");
assert_file_exists("$projectPath/releases/1/after-release-release");

// Assert current symlink exists and points to release 1
assert_symlink("$projectPath/current");
assert_directory_exists("$projectPath/releases/1");
assert_string_contains(readlink("$projectPath/current"), 'releases/1');

// Assert .env and storage in release are symlinks
assert_symlink("$projectPath/releases/1/.env");
assert_symlink("$projectPath/releases/1/storage");

// Assert bundle was extracted with --strip-components=1 (database.php should be in config/, not root)
assert_file_missing("$projectPath/releases/1/database.php");
assert_file_exists("$projectPath/releases/1/config/database.php");

// Deploy again - should skip because same bundle hash
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Bundle is already deployed (hash: HASH)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)
EXPECTED, $output);
assert_file_missing("$projectPath/releases/2");

// Deploy with --force should redeploy
array_map('unlink', glob("$worldPath/lit/cached-releases/*.tar"));

[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst"... (XK in X seconds)
Adding bundle to cache ($worldPath/lit/cached-releases/HASH.tar)
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
assert_symlink("$projectPath/current");
assert_string_contains(readlink("$projectPath/current"), 'releases/2');

// Rename release 2 to 9 to test numeric sorting (1,9,10 not 1,10,9)
rename("$projectPath/releases/2", "$projectPath/releases/9");
run_process(['ln', '-snf', "$projectPath/releases/9", "$projectPath/current"], $projectPath);

array_map('unlink', glob("$worldPath/lit/cached-releases/*.tar"));

[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst"... (XK in X seconds)
Adding bundle to cache ($worldPath/lit/cached-releases/HASH.tar)
Bundle is already deployed (hash: HASH)
Using "--force", redeploying...
Creating "$projectPath/releases/10" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/10"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_directory_exists("$projectPath/releases/10");
assert_directory_exists("$projectPath/releases/9");
assert_directory_exists("$projectPath/releases/1");
assert_string_contains(readlink("$projectPath/current"), 'releases/10');

// Another deploy to verify cleanup continues working
array_map('unlink', glob("$worldPath/lit/cached-releases/*.tar"));

[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst"... (XK in X seconds)
Adding bundle to cache ($worldPath/lit/cached-releases/HASH.tar)
Bundle is already deployed (hash: HASH)
Using "--force", redeploying...
Creating "$projectPath/releases/11" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/11"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_directory_exists("$projectPath/releases/11");
assert_directory_exists("$projectPath/releases/10");
assert_directory_exists("$projectPath/releases/9");
assert_string_contains(readlink("$projectPath/current"), 'releases/11');
