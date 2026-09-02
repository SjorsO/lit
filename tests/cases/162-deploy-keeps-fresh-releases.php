<?php

require __DIR__.'/../test-helpers.php';

// Test that deploy keeps every release replaced less than an hour ago,
// no matter how many there are. There is no cap on the release count.

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");
neutralize_hooks($projectPath);

chdir($projectPath);

// Seed 6 fresh dummy release directories as if prior deploys just ran
foreach ([1, 2, 3, 4, 5, 6] as $releaseId) {
    mkdir("$projectPath/releases/$releaseId");
    touch("$projectPath/releases/$releaseId/marker");
}

run_process(['ln', '-snf', "$projectPath/releases/6", "$projectPath/current"], $projectPath);

// Deploy creates release 7, all fresh releases survive
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
Finished successfully (in X seconds)
EXPECTED, $output);

assert_string_contains(readlink("$projectPath/current"), 'releases/7');

// Another deploy creates release 8, still nothing gets deleted
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Creating "$projectPath/releases/8" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/8"
Finished successfully (in X seconds)
EXPECTED, $output);

foreach ([1, 2, 3, 4, 5, 6, 7, 8] as $releaseId) {
    assert_directory_exists("$projectPath/releases/$releaseId");
}

assert_string_contains(readlink("$projectPath/current"), 'releases/8');

// Delete all releases and deploy again, the numbering should restart at 1
// (the current symlink still exists, pointing at nothing)
run_process(['rm', '-rf', ...glob("$projectPath/releases/*")], $projectPath);

[$statusCode] = lit('deploy', '--force');

assert_same(0, $statusCode);

assert_directory_exists("$projectPath/releases/1");
assert_string_contains(readlink("$projectPath/current"), 'releases/1');
