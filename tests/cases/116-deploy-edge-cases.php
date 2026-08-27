<?php

require __DIR__.'/../test-helpers.php';

// Test deploy command edge cases

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// Deploy with invalid arguments should fail
[$statusCode, $output] = lit('deploy', 'invalid-arg');

assert_same(1, $statusCode);
assert_same('usage: lit deploy [--force]', $output);

// Deploy with too many arguments should fail
[$statusCode, $output] = lit('deploy', '--force', 'extra');

assert_same(1, $statusCode);
assert_same('usage: lit deploy [--force]', $output);

// Non-numeric release directory should fail
mkdir("$projectPath/releases/not-a-number");

[$statusCode, $output] = lit('deploy');

assert_same(1, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
The name of "$projectPath/releases/not-a-number" is not fully numeric, this should never happen
Finished with errors (in X seconds)
EXPECTED, $output);

// Clean up invalid release directory
rmdir("$projectPath/releases/not-a-number");

// A stray file in the releases directory should also fail
file_put_contents("$projectPath/releases/stray.txt", "should never be here\n");

[$statusCode, $output] = lit('deploy');

assert_same(1, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
The name of "$projectPath/releases/stray.txt" is not fully numeric, this should never happen
Finished with errors (in X seconds)
EXPECTED, $output);

// The stray file was not deleted
assert_file_exists("$projectPath/releases/stray.txt");

unlink("$projectPath/releases/stray.txt");

// before-caching.sh exists but caching disabled should show warning
file_put_contents("$projectPath/hooks/before-caching.sh", "# caching hook\n");

[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Creating "$projectPath/releases/1" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Hook "hooks/before-caching.sh" exists but will not be used because release caching is disabled
Releasing the new deployment "$projectPath/releases/1"
Finished successfully (in X seconds)
EXPECTED, $output);
