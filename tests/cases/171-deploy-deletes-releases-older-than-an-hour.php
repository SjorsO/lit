<?php

require __DIR__.'/../test-helpers.php';

// Test that deploy deletes releases older than an hour,
// even when there are fewer than 6 releases

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");
neutralize_hooks($projectPath);

chdir($projectPath);

// Seed 4 dummy release directories as if prior deploys had run
foreach ([1, 2, 3, 4] as $releaseId) {
    mkdir("$projectPath/releases/$releaseId");
}

// Make releases 1, 2 and 4 two hours old, release 3 stays fresh
$twoHoursAgo = time() - 2 * 60 * 60;

touch("$projectPath/releases/1", $twoHoursAgo);
touch("$projectPath/releases/2", $twoHoursAgo);
touch("$projectPath/releases/4", $twoHoursAgo);

run_process(['ln', '-snf', "$projectPath/releases/4", "$projectPath/current"], $projectPath);

// Deploy creates release 5, the releases older than an hour are deleted
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "$projectPath/releases/5" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/5"
Deleting old release directory "$projectPath/releases/4"...
Deleting old release directory "$projectPath/releases/2"...
Deleting old release directory "$projectPath/releases/1"...
Finished successfully (in X seconds)
EXPECTED, $output);

assert_file_missing("$projectPath/releases/1");
assert_file_missing("$projectPath/releases/2");
assert_directory_exists("$projectPath/releases/3");
assert_file_missing("$projectPath/releases/4");
assert_directory_exists("$projectPath/releases/5");

assert_string_contains(readlink("$projectPath/current"), 'releases/5');
