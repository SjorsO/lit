<?php

require __DIR__.'/../test-helpers.php';

// Test cache invalidation when before-caching.sh hook content changes

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/hooks/before-release.sh", "\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// Enable caching with initial hook that creates a unique marker
[$statusCode] = lit('enable-git-release-caching');

assert_same(0, $statusCode);

file_put_contents("$projectPath/hooks/before-caching.sh", 'touch "$1/hook-version-1" && uuidgen > "$1/cache-marker"'."\n");

// First deploy - builds cache
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Cloning repository...
Running "lit/hooks/before-caching.sh"...
Caching release...
Creating "$projectPath/releases/1" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/1"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_file_exists("$projectPath/releases/1/hook-version-1");
assert_file_exists("$projectPath/releases/1/cache-marker");

// Save the original cache marker
$originalCacheMarker = file_get_contents("$projectPath/releases/1/cache-marker");

// Change the hook content
file_put_contents("$projectPath/hooks/before-caching.sh", 'touch "$1/hook-version-2" && uuidgen > "$1/cache-marker"'."\n");

// Second deploy - should detect hook changed and rebuild
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = replace_commits(normalize_output($output));

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Cached release found but hook changed, rebuilding...
Cloning repository...
Running "lit/hooks/before-caching.sh"...
Caching release...
Creating "$projectPath/releases/2" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);

// New release should have the new hook marker and a different cache marker
assert_file_exists("$projectPath/releases/2/hook-version-2");
assert_file_missing("$projectPath/releases/2/hook-version-1");

$newCacheMarker = file_get_contents("$projectPath/releases/2/cache-marker");

if ($originalCacheMarker === $newCacheMarker) {
    printf("Expected different cache markers after hook change\n");
    exit(1);
}

// Change the hook back to the original version
file_put_contents("$projectPath/hooks/before-caching.sh", 'touch "$1/hook-version-1" && uuidgen > "$1/cache-marker"'."\n");

// Third deploy - should reuse the original cache
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = replace_commits(normalize_output($output));

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Reusing deployment from cache
Creating "$projectPath/releases/3" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/3"
Finished successfully (in X seconds)
EXPECTED, $output);

// Should have the original hook marker and cache marker
assert_file_exists("$projectPath/releases/3/hook-version-1");
assert_file_content("$projectPath/releases/3/cache-marker", rtrim($originalCacheMarker, "\n"));
