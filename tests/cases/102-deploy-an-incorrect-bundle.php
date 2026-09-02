<?php

require __DIR__.'/../test-helpers.php';

// Test deploying a bundle with incorrect structure (missing top-level directory)
[$statusCode] = lit('init', 'https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-with-files-in-root-for-lit-tests.tar.zst', 'bundle-for-lit-tests');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/bundle-for-lit-tests";

chdir($projectPath);

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

[$statusCode, $output] = lit('deploy');

assert_same(1, $statusCode);

$output = normalize_output($output, preserveHashes: true);

assert_same(<<<EXPECTED
Checking bundle version from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-with-files-in-root-for-lit-tests.tar.zst.hash"... (in X seconds)
Downloading bundle from "https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-with-files-in-root-for-lit-tests.tar.zst"... (XK in X seconds)
Adding bundle to cache ($worldPath/lit/cached-releases/bb22dfeac05ac841f274afce3955dae95017ab5b.tar)
Creating "$projectPath/releases/1" for the new release...
Extracting bundle...

Error: Incorrect bundle structure.
All entries in the bundle must be in a top-level directory.

Run "tar -tf {bundle}" to check. Entries should look like:
  ./config/filesystems.php       (good)
  my-app/config/filesystems.php  (good)
  config/filesystems.php         (bad - missing top-level directory)

See: https://github.com/SjorsO/lit?tab=readme-ov-file#deploying-a-bundle

Deleting new but unreleased release directory "$projectPath/releases/1"
Finished with errors (in X seconds)
EXPECTED, $output);
