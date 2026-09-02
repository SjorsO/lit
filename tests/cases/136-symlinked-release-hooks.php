<?php

require __DIR__.'/../test-helpers.php';

// Test that symlinked before-release and after-release hooks work correctly

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/lit";

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

// Create shared hooks outside the project directory
mkdir("$worldPath/shared-hooks");

file_put_contents("$worldPath/shared-hooks/before-release.sh", 'touch "$1/before-release-ran"'."\n");
file_put_contents("$worldPath/shared-hooks/after-release.sh", 'touch "$1/after-release-ran"'."\n");

// Replace hooks with symlinks to shared hooks
unlink("$projectPath/hooks/before-release.sh");
unlink("$projectPath/hooks/after-release.sh");

symlink("$worldPath/shared-hooks/before-release.sh", "$projectPath/hooks/before-release.sh");
symlink("$worldPath/shared-hooks/after-release.sh", "$projectPath/hooks/after-release.sh");

// Verify they are symlinks
assert_symlink("$projectPath/hooks/before-release.sh");
assert_symlink("$projectPath/hooks/after-release.sh");

chdir($projectPath);

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

// Assert symlinked hooks ran correctly
assert_file_exists("$projectPath/before-release-ran");
assert_file_exists("$projectPath/after-release-ran");
