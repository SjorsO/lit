<?php

require __DIR__.'/../test-helpers.php';

// Test that cache is pruned when it exceeds 500MB

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/lit";

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

[$statusCode] = lit('enable-git-release-caching');

assert_same(0, $statusCode);

neutralize_hooks($projectPath);
file_put_contents("$projectPath/hooks/before-caching.sh", "# no-op\n");

// Create 4 fake cache files of 200MB each (800MB total, exceeds 500MB limit)
if (! is_dir("$worldPath/lit/cached-releases")) {
    mkdir("$worldPath/lit/cached-releases", 0777, true);
}

$fakeCacheFiles = [
    'oldest-file.tar' => 4,
    'second-oldest-file.tar' => 3,
    'second-newest-file.tar' => 2,
    'newest-file.tar' => 1,
];

foreach ($fakeCacheFiles as $fakeCacheFile => $daysOld) {
    run_process(['bash', '-c', "head -c 200000000 /dev/zero > \"$worldPath/lit/cached-releases/$fakeCacheFile\""], $worldPath);

    touch("$worldPath/lit/cached-releases/$fakeCacheFile", time() - $daysOld * 24 * 60 * 60);
}

// Verify all files exist before deploy
assert_file_exists("$worldPath/lit/cached-releases/oldest-file.tar");
assert_file_exists("$worldPath/lit/cached-releases/second-oldest-file.tar");
assert_file_exists("$worldPath/lit/cached-releases/second-newest-file.tar");
assert_file_exists("$worldPath/lit/cached-releases/newest-file.tar");

// Deploy (this should prune cache to under 500MB)
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

// Two oldest files should be deleted (800MB -> 400MB, under 500MB limit)
assert_file_missing("$worldPath/lit/cached-releases/oldest-file.tar");
assert_file_missing("$worldPath/lit/cached-releases/second-oldest-file.tar");

// Two newest files should still exist
assert_file_exists("$worldPath/lit/cached-releases/second-newest-file.tar");
assert_file_exists("$worldPath/lit/cached-releases/newest-file.tar");

// The deploy also creates a new cache file, so we should have 3 files total
assert_same(3, count(glob("$worldPath/lit/cached-releases/*.tar")));
