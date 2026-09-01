<?php

require __DIR__.'/../test-helpers.php';

// A cached bundle used to be trusted because its filename matched the hash.
// Anything under that name was deployed, and the wrong hash was recorded.
// Now the bytes are hashed before the cached bundle is used.

$bundleUrl = 'https://sjorso-public-files.s3-eu-central-1.amazonaws.com/lit-fixtures/bundle-for-lit-tests.tar.zst';

[$statusCode] = lit('init', $bundleUrl);

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/bundle-for-lit-tests";

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

[$statusCode] = lit('deploy');

assert_same(0, $statusCode);

$bundleHash = lit_state($projectPath)['bundle_hash'];
$cachedBundlePath = "$worldPath/lit/cached-releases/$bundleHash.tar";

assert_file_exists($cachedBundlePath);

// Replace the cached bundle with a different, but perfectly valid, tar.
// The filename keeps claiming the hash of the real bundle.
mkdir("$worldPath/wrong-bundle/wrong-app", recursive: true);
file_put_contents("$worldPath/wrong-bundle/wrong-app/i-am-the-wrong-bundle", "yes\n");

run_process(['tar', '-cf', $cachedBundlePath, 'wrong-app'], "$worldPath/wrong-bundle");

if (sha1_file($cachedBundlePath) === $bundleHash) {
    printf("Expected the corrupted cache file to have a different hash\n");
    exit(1);
}

// Deploy again, the cache entry matches the remote hash so it would be reused
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

assert_string_contains($output, "The cached bundle (hash: $bundleHash) is corrupt, deleting it");
assert_string_contains($output, 'Downloading bundle from');
assert_string_not_contains($output, 'Using cached bundle');

// The wrong bundle must never reach a release
assert_file_missing("$projectPath/releases/2/i-am-the-wrong-bundle");
assert_file_missing("$projectPath/current/i-am-the-wrong-bundle");

// The cache holds the real bundle again
assert_file_exists($cachedBundlePath);
assert_same($bundleHash, sha1_file($cachedBundlePath));

assert_lit_state_value($projectPath, 'bundle_hash', $bundleHash);
