<?php

require __DIR__.'/../test-helpers.php';

// The release cache is shared by every project on the server.
// The project lock does not cover it, so pruning takes its own lock.
// While another deploy is using the cache, pruning must skip.

$worldPath = world_path();
$caseDir = "$worldPath/case";

$seedPath = "$caseDir/seed";
$remotePath = "$caseDir/origin-repo.git";
$remoteUrl = "file://$remotePath";

$gitCommand = ['git', '-c', 'user.email=lit@test', '-c', 'user.name=lit'];

run_process(['git', 'init', '--quiet', '--initial-branch=main', $seedPath], $caseDir);

file_put_contents("$seedPath/app.txt", "one\n");
run_process(['git', 'add', 'app.txt'], $seedPath);
run_process([...$gitCommand, 'commit', '--quiet', '-m', 'one'], $seedPath);

run_process(['git', 'clone', '--quiet', '--bare', $seedPath, $remotePath], $caseDir);

chdir($caseDir);

[$statusCode] = lit('init', $remoteUrl);

assert_same(0, $statusCode);

$projectPath = "$caseDir/origin-repo";

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

[$statusCode] = lit('enable-git-release-caching');

assert_same(0, $statusCode);

file_put_contents("$projectPath/hooks/before-caching.sh", "# no-op\n");

[$statusCode] = lit('deploy');

assert_same(0, $statusCode);

// A cache entry from another project, old enough to be pruned
$otherProjectCachePath = "$worldPath/lit/cached-releases/other-project.tar";

touch($otherProjectCachePath, time() - 8 * 24 * 60 * 60);

// Pretend another project is using the cache right now
$cacheLockFile = fopen("$worldPath/lit/data/cached-releases-lock", 'c');

if (! flock($cacheLockFile, LOCK_SH)) {
    printf("Could not take the cache lock\n");
    exit(1);
}

[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

assert_string_contains($output, 'Another project is using the release cache, skipping the cache cleanup');

// Pruning was skipped, deleting this file could have broken the other project
assert_file_exists($otherProjectCachePath);

flock($cacheLockFile, LOCK_UN);
fclose($cacheLockFile);

// Nobody is using the cache anymore, the next deploy prunes
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

assert_string_not_contains($output, 'skipping the cache cleanup');

assert_file_missing($otherProjectCachePath);
