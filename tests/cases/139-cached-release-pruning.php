<?php

require __DIR__.'/../test-helpers.php';

// Test that cached releases older than 7 days are pruned

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/lit";

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

[$statusCode] = lit('enable-git-release-caching');

assert_same(0, $statusCode);

// Override hooks to do nothing
file_put_contents("$projectPath/hooks/before-release.sh", "# no-op\n");
file_put_contents("$projectPath/hooks/after-release.sh", "# no-op\n");
file_put_contents("$projectPath/hooks/before-caching.sh", "# no-op\n");

// Create fake old cached releases (8 days old)
if (! is_dir("$worldPath/lit/cached-releases")) {
    mkdir("$worldPath/lit/cached-releases", 0777, true);
}

foreach (['old-cache-abc123.tar', 'old-cache-def456.tar', 'old-cache-bundle789.tar'] as $oldCacheFile) {
    touch("$worldPath/lit/cached-releases/$oldCacheFile", time() - 8 * 24 * 60 * 60);
}

// Create a recent cached release (should NOT be deleted)
touch("$worldPath/lit/cached-releases/recent-cache-ghi789.tar");

// Verify all files exist before deploy
assert_file_exists("$worldPath/lit/cached-releases/old-cache-abc123.tar");
assert_file_exists("$worldPath/lit/cached-releases/old-cache-def456.tar");
assert_file_exists("$worldPath/lit/cached-releases/old-cache-bundle789.tar");
assert_file_exists("$worldPath/lit/cached-releases/recent-cache-ghi789.tar");

// Deploy (this should prune old cached releases)
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

// Old cached releases should be deleted
assert_file_missing("$worldPath/lit/cached-releases/old-cache-abc123.tar");
assert_file_missing("$worldPath/lit/cached-releases/old-cache-def456.tar");
assert_file_missing("$worldPath/lit/cached-releases/old-cache-bundle789.tar");

// Recent cached release should still exist
assert_file_exists("$worldPath/lit/cached-releases/recent-cache-ghi789.tar");

// The actual cache created by this deploy should also exist
assert_same(2, count(glob("$worldPath/lit/cached-releases/*.tar")));
