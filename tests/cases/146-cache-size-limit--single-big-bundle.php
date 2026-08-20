<?php

require __DIR__.'/../test-helpers.php';

// Test that we always keep at least 1 cache entry even if it's >500MB

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/lit";

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

[$statusCode] = lit('enable-git-release-caching');

assert_same(0, $statusCode);

neutralize_hooks($projectPath);

file_put_contents("$projectPath/hooks/before-caching.sh", 'head -c 600000000 /dev/urandom > "$1/big-file.bin"'."\n");

// First deploy - creates a cache entry >500MB
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);
assert_directory_exists("$projectPath/releases/1");

// Find the first cache file
$firstCacheFile = glob("$worldPath/lit/cached-releases/*.tar")[0] ?? '';

assert_file_exists($firstCacheFile);

// Change the hook so we get a different cache entry
file_put_contents("$projectPath/hooks/before-caching.sh", 'head -c 600000000 /dev/urandom > "$1/different-big-file.bin"'."\n");

// Second deploy - creates another cache entry >500MB
// This should delete the first cache (>500MB limit) but keep the new one (always keep 1)
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);
assert_directory_exists("$projectPath/releases/2");

// First cache file should be deleted
assert_file_missing($firstCacheFile);

// Should have exactly 1 cache file (the new one)
assert_same(1, count(glob("$worldPath/lit/cached-releases/*.tar")));
