<?php

require __DIR__.'/../test-helpers.php';

// Test that symlinked hooks work and are not deleted/overwritten by lit init

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/lit";

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

// Remove the default hooks created by init
unlink("$projectPath/hooks/before-release.sh");
unlink("$projectPath/hooks/after-release.sh");
unlink("$projectPath/hooks/on-failure.sh");

// Create shared hooks directory outside the project
mkdir("$worldPath/case/shared-hooks");

file_put_contents("$worldPath/case/shared-hooks/before-release.sh", 'touch "$1/before-release-ran"'."\n");
file_put_contents("$worldPath/case/shared-hooks/after-release.sh", 'touch "$1/after-release-ran"'."\n");
file_put_contents("$worldPath/case/shared-hooks/on-failure.sh", 'touch "$1/on-failure-ran"'."\n");

// Symlink all hooks
symlink("$worldPath/case/shared-hooks/before-release.sh", "$projectPath/hooks/before-release.sh");
symlink("$worldPath/case/shared-hooks/after-release.sh", "$projectPath/hooks/after-release.sh");
symlink("$worldPath/case/shared-hooks/on-failure.sh", "$projectPath/hooks/on-failure.sh");

// Verify they are symlinks
assert_symlink("$projectPath/hooks/before-release.sh");
assert_symlink("$projectPath/hooks/after-release.sh");
assert_symlink("$projectPath/hooks/on-failure.sh");

// Deploy and verify symlinked hooks run
chdir($projectPath);

[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);
assert_file_exists("$projectPath/before-release-ran");
assert_file_exists("$projectPath/after-release-ran");

// on-failure should NOT have run (deploy succeeded)
assert_file_missing("$projectPath/on-failure-ran");

// Record the symlink targets before re-init
$beforeReleaseTarget = readlink("$projectPath/hooks/before-release.sh");
$afterReleaseTarget = readlink("$projectPath/hooks/after-release.sh");
$onFailureTarget = readlink("$projectPath/hooks/on-failure.sh");

// Re-run lit init on the same project (simulating updating the URL or re-initializing)
chdir("$worldPath/case");

[$statusCode, $output] = lit('init', 'https://github.com/SjorsO/lit.git', 'lit');

assert_same(0, $statusCode);

// Assert symlinks still exist and point to the same targets
assert_symlink("$projectPath/hooks/before-release.sh");
assert_symlink("$projectPath/hooks/after-release.sh");
assert_symlink("$projectPath/hooks/on-failure.sh");

assert_same($beforeReleaseTarget, readlink("$projectPath/hooks/before-release.sh"));
assert_same($afterReleaseTarget, readlink("$projectPath/hooks/after-release.sh"));
assert_same($onFailureTarget, readlink("$projectPath/hooks/on-failure.sh"));

// Clean up marker files and deploy again to verify hooks still work after re-init
unlink("$projectPath/before-release-ran");
unlink("$projectPath/after-release-ran");

chdir($projectPath);

[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);
assert_file_exists("$projectPath/before-release-ran");
assert_file_exists("$projectPath/after-release-ran");

// Test that on-failure hook also works as a symlink by making after-release fail
file_put_contents("$worldPath/case/shared-hooks/after-release.sh", "exit 1\n");

// Clean up marker files
unlink("$projectPath/before-release-ran");
unlink("$projectPath/after-release-ran");

[$statusCode, $output] = lit('deploy', '--force');

assert_same(1, $statusCode);
assert_file_exists("$projectPath/before-release-ran");
assert_file_exists("$projectPath/on-failure-ran");
