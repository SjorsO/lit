<?php

require __DIR__.'/../test-helpers.php';

// Mock curl via the world "bin" directory (prepended to PATH by the test helpers)
// to simulate success without making HTTP requests

$worldPath = world_path();

mkdir("$worldPath/bin");

file_put_contents("$worldPath/bin/curl", "#!/bin/bash\nprintf 'OPcache flushed successfully (simulated).\\n'\n");

chmod("$worldPath/bin/curl", 0755);

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = "$worldPath/case/lit";

chdir($projectPath);

file_put_contents("$projectPath/.env", "APP_URL=https://example.com/\n");

// Set up hooks
file_put_contents("$projectPath/hooks/before-release.sh", "# no-op\n");

file_put_contents("$projectPath/hooks/after-release.sh", <<<'HOOK'
project_base_directory="$1"
new_release_directory="$2"
lit_base_path="$3"

mkdir -p "$new_release_directory/public"

(cd "$project_base_directory" && php "$lit_base_path/lit.php" flush-opcache)
HOOK."\n");

// First deploy (flush-opcache will say "first deployment" which is correct)
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
Not flushing OPcache because this appears to be the first deployment
Finished successfully (in X seconds)
EXPECTED, $output);

// Create public directory for release 1 (in case hook ran before we could)
if (! is_dir("$projectPath/releases/1/public")) {
    mkdir("$projectPath/releases/1/public", 0777, true);
}

// Second deploy triggers flush-opcache via hook
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Creating "$projectPath/releases/2" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/2"
Calling "https://example.com" to flush OPcache.
OPcache flushed successfully (simulated).
Finished successfully (in X seconds)
EXPECTED, $output);

// Verify the temporary PHP files were cleaned up
assert_same(0, count(glob("$projectPath/current/public/lit-*.php")));
assert_same(0, count(glob("$projectPath/releases/1/public/lit-*.php")));

// Test APP_URL with single quotes
file_put_contents("$projectPath/.env", "APP_URL='https://single-quoted.com'\n");

[$statusCode, $output] = lit('flush-opcache');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Calling "https://single-quoted.com" to flush OPcache.
OPcache flushed successfully (simulated).
EXPECTED, $output);

// Test APP_URL with double quotes
file_put_contents("$projectPath/.env", "APP_URL=\"https://double-quoted.com\"\n");

[$statusCode, $output] = lit('flush-opcache');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Calling "https://double-quoted.com" to flush OPcache.
OPcache flushed successfully (simulated).
EXPECTED, $output);

// Test error cases

// Missing APP_URL
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

[$statusCode, $output] = lit('flush-opcache');

assert_same(1, $statusCode);
assert_same('Unable to flush OPcache, APP_URL not found in .env file', $output);

// Missing .env file
unlink("$projectPath/.env");

[$statusCode, $output] = lit('flush-opcache');

assert_same(1, $statusCode);
assert_same('Unable to flush OPcache, no .env file found', $output);

// A Deployer-style project keeps its .env in the "shared" directory
mkdir("$projectPath/shared");

file_put_contents("$projectPath/shared/.env", "APP_URL=https://shared-env.com\n");

[$statusCode, $output] = lit('flush-opcache');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Calling "https://shared-env.com" to flush OPcache.
OPcache flushed successfully (simulated).
EXPECTED, $output);
