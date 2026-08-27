<?php

require __DIR__.'/../test-helpers.php';

// Test switching from git to bundle deployment

$worldPath = world_path();
$projectPath = "$worldPath/case/my-app";

// Init git repo
[$statusCode, $output] = lit('init', 'https://github.com/SjorsO/lit.git', 'my-app');

assert_same(0, $statusCode);

chdir($projectPath);

file_put_contents("$projectPath/.env", "APP_KEY=test\n");
neutralize_hooks($projectPath);

// Deploy git repo
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "$projectPath/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/1"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_symlink("$projectPath/current");
assert_directory_exists("$projectPath/releases/1");

// Verify current points to release 1
assert_string_contains(readlink("$projectPath/current"), 'releases/1');

// Switch to bundle
[$statusCode, $output] = lit('init', 'https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst', '.');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Changing from git URL: https://github.com/SjorsO/lit.git (branch: main)
Bundle URL set to "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst"

Finished initializing "my-app"

Next steps:
- Review these hooks:
  - "hooks/before-release.sh"
  - "hooks/after-release.sh"
  - "hooks/on-failure.sh"

After that, run "lit deploy" to download and deploy the bundle
EXPECTED, $output);

// Git keys should be removed
assert_lit_state_missing($projectPath, 'git_repository_url');
assert_lit_state_missing($projectPath, 'git_branch');

// Bundle keys should exist
assert_lit_state_value($projectPath, 'bundle_url', 'https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst');

// Clear bundle cache to ensure we test the download path
array_map('unlink', glob("$worldPath/lit/cached-releases/*.tar"));

// Deploy bundle
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst.hash"... (in X seconds)
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst"... (XK in X seconds)
Adding bundle to cache ($worldPath/lit/cached-releases/HASH.tar)
Creating "$projectPath/releases/2" for the new release...
Extracting bundle...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_directory_exists("$projectPath/releases/2");

// Verify current points to release 2
assert_string_contains(readlink("$projectPath/current"), 'releases/2');

// Switch back to git
[$statusCode, $output] = lit('init', 'https://github.com/SjorsO/lit.git', '.');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Reading "https://github.com/SjorsO/lit.git"... Done!

Changing from bundle URL: https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst
Current branch set to "main"

Finished initializing "my-app"

Next steps:
- Review these hooks:
  - "hooks/before-release.sh"
  - "hooks/after-release.sh"
  - "hooks/on-failure.sh"

After that, either:
- Run "lit deploy" to deploy the current branch (main)
- Run "lit checkout <branch>" to deploy a different branch
EXPECTED, $output);

// Bundle keys should be removed
assert_lit_state_missing($projectPath, 'bundle_url');
assert_lit_state_missing($projectPath, 'bundle_hash');

// Git keys should exist
assert_lit_state_value($projectPath, 'git_repository_url', 'https://github.com/SjorsO/lit.git');
assert_lit_state_value($projectPath, 'git_branch', 'main');

// Deploy git repo again
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "$projectPath/releases/3" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/3"
Finished successfully (in X seconds)
EXPECTED, $output);

assert_directory_exists("$projectPath/releases/3");

// Verify current points to release 3
assert_string_contains(readlink("$projectPath/current"), 'releases/3');
