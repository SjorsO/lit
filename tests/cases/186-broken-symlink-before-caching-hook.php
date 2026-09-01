<?php

require __DIR__.'/../test-helpers.php';

// Test caching when before-caching.sh is a symlink pointing at a missing file

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

[$statusCode] = lit('enable-git-release-caching');

assert_same(0, $statusCode);

// Replace the hook with a symlink to a file that doesn't exist
unlink("$projectPath/hooks/before-caching.sh");
symlink(world_path().'/case/gone/before-caching.sh', "$projectPath/hooks/before-caching.sh");

assert_symlink("$projectPath/hooks/before-caching.sh");

// Deploy should treat the broken symlink as a missing hook
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Cloning repository...
Wanted to run "$projectPath/hooks/before-caching.sh" but it does not exist
Caching release...
Creating "$projectPath/releases/1" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/1"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_directory_exists("$projectPath/releases/1");
