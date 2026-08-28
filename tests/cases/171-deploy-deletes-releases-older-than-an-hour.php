<?php

require __DIR__.'/../test-helpers.php';

// Test that deploy deletes releases replaced more than an hour ago,
// even when there are fewer than 6 releases. The release being replaced
// gets a fresh timestamp, jobs still running on it get an hour of grace.

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

// Deploy creates release 5. Releases 1 and 2 were replaced over an hour
// ago, they get deleted. Release 4 is old, but it was live until right
// now, so it survives this deploy. Release 3 is fresh, it survives too.
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
Deleting old release directory "$projectPath/releases/2"...
Deleting old release directory "$projectPath/releases/1"...
Finished successfully (in X seconds)
EXPECTED, $output);

assert_file_missing("$projectPath/releases/1");
assert_file_missing("$projectPath/releases/2");
assert_directory_exists("$projectPath/releases/3");
assert_directory_exists("$projectPath/releases/4");
assert_directory_exists("$projectPath/releases/5");

assert_string_contains(readlink("$projectPath/current"), 'releases/5');

// Age releases 3 and 4 again, then redeploy. Release 4 is no longer
// the one being replaced, so this time it gets pruned. Release 5 is
// being replaced now, it gets the fresh timestamp and survives.
touch("$projectPath/releases/3", $twoHoursAgo);
touch("$projectPath/releases/4", $twoHoursAgo);
touch("$projectPath/releases/5", $twoHoursAgo);

[$statusCode, $output] = lit('redeploy');

assert_same(0, $statusCode);

assert_file_missing("$projectPath/releases/3");
assert_file_missing("$projectPath/releases/4");
assert_directory_exists("$projectPath/releases/5");
assert_directory_exists("$projectPath/releases/6");

assert_string_contains(readlink("$projectPath/current"), 'releases/6');
