<?php

require __DIR__.'/../test-helpers.php';

// Test that deploy cleans up old releases, keeping only the 6 newest

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");
file_put_contents("$projectPath/hooks/before-release.sh", "\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");

chdir($projectPath);

// Seed 6 dummy release directories as if prior deploys had run
foreach ([1, 2, 3, 4, 5, 6] as $releaseId) {
    mkdir("$projectPath/releases/$releaseId");
    touch("$projectPath/releases/$releaseId/marker");
}

run_process(['ln', '-snf', "$projectPath/releases/6", "$projectPath/current"], $projectPath);

// Deploy should create release 7 and delete release 1 (oldest beyond the 6 kept)
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "$projectPath/releases/7" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/7"
Deleting old release directory "$projectPath/releases/1"...
Finished successfully (in X seconds)
EXPECTED, $output);

assert_file_missing("$projectPath/releases/1");
assert_directory_exists("$projectPath/releases/2");
assert_directory_exists("$projectPath/releases/3");
assert_directory_exists("$projectPath/releases/4");
assert_directory_exists("$projectPath/releases/5");
assert_directory_exists("$projectPath/releases/6");
assert_directory_exists("$projectPath/releases/7");

assert_string_contains(readlink("$projectPath/current"), 'releases/7');

// Another deploy should create release 8 and delete release 2
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = replace_commits(normalize_output($output));

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Creating "$projectPath/releases/8" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/8"
Deleting old release directory "$projectPath/releases/2"...
Finished successfully (in X seconds)
EXPECTED, $output);

assert_file_missing("$projectPath/releases/1");
assert_file_missing("$projectPath/releases/2");
assert_directory_exists("$projectPath/releases/3");
assert_directory_exists("$projectPath/releases/4");
assert_directory_exists("$projectPath/releases/5");
assert_directory_exists("$projectPath/releases/6");
assert_directory_exists("$projectPath/releases/7");
assert_directory_exists("$projectPath/releases/8");
