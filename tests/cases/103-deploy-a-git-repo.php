<?php

require __DIR__.'/../test-helpers.php';

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

// Replace hooks to verify $1 and $2 are correct directories
// $1 (project_base_directory) should contain storage/ and logs/
// $2 (new_release_directory) should contain the cloned repo (lit.sh)
file_put_contents("$projectPath/hooks/before-release.sh", '[ -d "$1/storage" ] && [ -d "$1/logs" ] && [ -f "$2/lit.sh" ] && touch "$1/before-release-ran" && touch "$2/before-release-release"'."\n");
file_put_contents("$projectPath/hooks/after-release.sh", '[ -d "$1/storage" ] && [ -d "$1/logs" ] && [ -f "$2/lit.sh" ] && touch "$1/after-release-ran" && touch "$2/after-release-release"'."\n");

chdir($projectPath);

// First deploy with empty .env should fail
[$statusCode, $output] = lit('deploy');

assert_same(1, $statusCode);
assert_same('Your ".env" file is empty, try again when you have filled it in', $output);
assert_file_missing("$projectPath/current");

// Fill in .env and deploy again
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

// Assert runtime format is X.XXs (e.g., "1.05s")
assert_matches('/\(in [0-9]+\.[0-9]{2}s\)/', $output);

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

// Assert lit.log has correct timestamp format (YYYY-MM-DD HH:MM:SS)
assert_matches('/\[[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}\]/', file_get_contents("$projectPath/logs/lit.log"));

// Deploy again - should skip because already deployed
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = replace_commits(normalize_output($output));

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)
EXPECTED, $output);
assert_file_missing("$projectPath/releases/2");

// Deploy with --force should redeploy
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = replace_commits(normalize_output($output));

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Creating "$projectPath/releases/2" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_directory_exists("$projectPath/releases/2");
assert_symlink("$projectPath/current");

// Verify current points to release 2
assert_string_contains(readlink("$projectPath/current"), 'releases/2');

// Rename release 2 to 9 to test numeric sorting (1,9,10 not 1,10,9)
rename("$projectPath/releases/2", "$projectPath/releases/9");
run_process(['ln', '-snf', "$projectPath/releases/9", "$projectPath/current"], $projectPath);

[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = replace_commits(normalize_output($output));

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Creating "$projectPath/releases/10" for the new release...
Cloning repository...
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
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = replace_commits(normalize_output($output));

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Creating "$projectPath/releases/11" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/11"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_directory_exists("$projectPath/releases/11");
assert_directory_exists("$projectPath/releases/10");
assert_directory_exists("$projectPath/releases/9");
assert_string_contains(readlink("$projectPath/current"), 'releases/11');
