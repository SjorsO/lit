<?php

/**
 * @var string $litBasePath
 * @var string $projectBasePath
 * @var string[] $arguments
 */

$firstOption = $arguments[1] ?? '';
$secondOption = $arguments[2] ?? '';

$currentRemoteCommit = '';
$isRedeploying = false;

if ($firstOption === '--use-commit-from-checkout') {
    $currentRemoteCommit = $secondOption;
} elseif ($firstOption === '--redeploy') {
    $currentRemoteCommit = $secondOption;
    $isRedeploying = true;
} elseif ($secondOption !== '' || ($firstOption !== '' && $firstOption !== '--force')) {
    out("usage: lit deploy [--force]\n");

    $GLOBALS['current_run_result'] = 'failed (invalid usage)';

    lit_exit(1);
}

$isForcing = $firstOption === '--force';

$sourceType = get_source_type($projectBasePath);

// This should never happen unless files were manually tampered with.
if ($sourceType !== 'git' && $sourceType !== 'bundle') {
    out("Invalid source type: \"$sourceType\"\n");

    $GLOBALS['current_run_result'] = 'failed (invalid source type)';

    lit_exit(1);
}

$startedAt = current_time_in_ms();

$releasesDirectory = "$projectBasePath/releases";
$currentDirectoryPath = "$projectBasePath/current";

// Projects previously deployed with Deployer have a "shared" directory.
$realStorageDirectoryPath = is_dir("$projectBasePath/shared/storage") ? "$projectBasePath/shared/storage" : "$projectBasePath/storage";
$realEnvFilePath = file_exists("$projectBasePath/shared/.env") ? "$projectBasePath/shared/.env" : "$projectBasePath/.env";

if (! file_exists($realEnvFilePath) || filesize($realEnvFilePath) === 0) {
    touch($realEnvFilePath);

    out("Your \".env\" file is empty, try again when you have filled it in\n");

    $GLOBALS['current_run_result'] = 'aborted, the ".env" file is empty';

    lit_exit(1);
}

$state = new stdClass;
$state->releaseDirectoryCreated = false;
$state->wasReleased = false;
$state->newReleaseDirectory = '';
$state->tempDirectoryPath = '';
$state->stagingDirectoryPath = '';
$state->currentRef = '';
$state->currentRefType = 'branch';
$state->currentCommit = '';
$state->newBundleHash = '';

register_cleanup(function (int $exitCode) use ($state, $projectBasePath, $sourceType, $startedAt) {
    chdir($projectBasePath);

    if ($state->releaseDirectoryCreated && ! $state->wasReleased) {
        out("Deleting new but unreleased release directory \"{$state->newReleaseDirectory}\"\n");

        delete_directory($state->newReleaseDirectory);
    }

    // Clean up temp directories from cache building if they still exist
    if ($state->tempDirectoryPath !== '' && is_dir($state->tempDirectoryPath)) {
        delete_directory($state->tempDirectoryPath);
    }

    if ($state->stagingDirectoryPath !== '' && is_dir($state->stagingDirectoryPath)) {
        delete_directory($state->stagingDirectoryPath);
    }

    if ($GLOBALS['current_run_result'] === '') {
        $shortCommit = substr($state->currentCommit, 0, 11);
        $result = null;

        // "branch "main" (commit: abc123)", "tag "v1.0" (commit: abc123)", or "commit abc123"
        $refDescription = $state->currentRefType === 'commit'
            ? "commit $shortCommit"
            : "{$state->currentRefType} \"{$state->currentRef}\" (commit: $shortCommit)";

        if ($state->wasReleased && $exitCode !== 0 && $sourceType === 'git') {
            $result = "had errors, still deployed $refDescription";
        } elseif ($state->wasReleased && $exitCode !== 0 && $sourceType === 'bundle') {
            $result = "had errors, still deployed bundle (hash: {$state->newBundleHash})";
        } elseif ($state->wasReleased && $sourceType === 'git') {
            $result = "deployed $refDescription";
        } elseif ($state->wasReleased && $sourceType === 'bundle') {
            $result = "deployed bundle (hash: {$state->newBundleHash})";
        } elseif ($state->releaseDirectoryCreated) {
            $result = 'failed, deployment was not released';
        } elseif ($exitCode !== 0) {
            $result = 'failed';
        }

        if ($result !== null) {
            $GLOBALS['current_run_result'] = $result;
        }
    }

    if ($state->wasReleased && $exitCode !== 0) {
        out(">\n");
        out("> Warning: The new deployment was still released!\n");
        out(">\n");
    }

    if ($exitCode !== 0) {
        if (file_exists("$projectBasePath/hooks/on-failure.sh")) {
            if (run_hook("$projectBasePath/hooks/on-failure.sh", [$projectBasePath, $state->wasReleased ? 'true' : 'false']) !== 0) {
                out("The on-failure hook failed\n");
            }
        } else {
            out("Wanted to run \"$projectBasePath/hooks/on-failure.sh\" but it does not exist\n");
        }
    }

    $prettyRuntime = pretty_runtime(current_time_in_ms() - $startedAt);
    $finishedWord = $exitCode !== 0 ? 'with errors' : 'successfully';

    out("Finished $finishedWord (in $prettyRuntime)\n");
});

foreach (glob("$releasesDirectory/*") ?: [] as $releasePath) {
    if (! preg_match('/^[0-9]+$/', basename($releasePath))) {
        out("The name of \"$releasePath\" is not fully numeric, this should never happen\n");

        $GLOBALS['current_run_result'] = 'failed, the releases directory contains an invalid name';

        lit_exit(1);
    }
}

$releaseIds = array_map('basename', glob("$releasesDirectory/*") ?: []);

sort($releaseIds, SORT_NUMERIC);

$currentReleaseId = (int) (end($releaseIds) ?: 0);

$state->newReleaseDirectory = "$releasesDirectory/".($currentReleaseId + 1);

$previousReleaseDirectory = '';

if (is_link($currentDirectoryPath)) {
    $previousReleaseDirectory = "$releasesDirectory/".basename(readlink($currentDirectoryPath));
}

$litState = read_lit_state($projectBasePath);

if ($sourceType === 'git') {
    $gitRepositoryUrl = $litState['git_repository_url'];
    $state->currentRef = $litState['git_ref'] ?? '';
    $state->currentRefType = $litState['git_ref_type'] ?? 'branch';
    $state->currentCommit = $litState['git_commit_sha'] ?? 'not deployed yet';

    // Remember the old commit, the commit log draws an arrow from it
    $previousCommit = $litState['git_commit_sha'] ?? '';

    // A redeploy pins the exact commit, even when the ref is a branch or tag
    if ($isRedeploying) {
        $state->currentRef = $currentRemoteCommit;
        $state->currentRefType = 'commit';
    }

    // If we are deploying after a "lit checkout", then we already have the commit.
    if ($currentRemoteCommit === '') {
        if ($state->currentRefType === 'commit') {
            // The ref is the commit, nothing to resolve
            $currentRemoteCommit = $state->currentRef;
        } else {
            out("Reading {$state->currentRefType} \"{$state->currentRef}\" of \"$gitRepositoryUrl\"... ");

            [$branchCommit, $tagCommit] = resolve_remote_ref($gitRepositoryUrl, $state->currentRef);

            $currentRemoteCommit = $state->currentRefType === 'tag' ? $tagCommit : $branchCommit;

            out("\n");

            // Stop right away, deploying an empty commit would fail later at the clone
            if ($currentRemoteCommit === '') {
                out(ucfirst($state->currentRefType)." \"{$state->currentRef}\" does not exist on the remote\n");

                $GLOBALS['current_run_result'] = "failed (the {$state->currentRefType} does not exist on the remote)";

                lit_exit(1);
            }
        }
    }

    if (! $isRedeploying && $state->currentCommit === $currentRemoteCommit) {
        $shortRemoteCommit = substr($currentRemoteCommit, 0, 11);

        if ($state->currentRefType === 'branch') {
            out("Latest commit of \"{$state->currentRef}\" is already deployed ($shortRemoteCommit)\n");
        } elseif ($state->currentRefType === 'tag') {
            out("Tag \"{$state->currentRef}\" is already deployed ($shortRemoteCommit)\n");
        } else {
            out("Commit is already deployed ($shortRemoteCommit)\n");
        }

        if ($isForcing) {
            out("Using \"--force\", redeploying...\n");
        } else {
            out("Run \"lit deploy --force\" to redeploy\n");

            $GLOBALS['current_run_result'] = 'aborted, this commit is already deployed';

            lit_exit(0);
        }
    }

    $cachingEnabled = ($litState['git_release_caching_enabled'] ?? false) === true;

    if ($cachingEnabled) {
        if (is_link("$projectBasePath/hooks/before-caching.sh")) {
            $beforeCachingHookPath = realpath("$projectBasePath/hooks/before-caching.sh");
            $beforeCachingHookHash = sha1_file($beforeCachingHookPath);
        } elseif (file_exists("$projectBasePath/hooks/before-caching.sh")) {
            $beforeCachingHookPath = "$projectBasePath/hooks/before-caching.sh";
            $beforeCachingHookHash = sha1_file($beforeCachingHookPath);
        } else {
            $beforeCachingHookPath = '';
            $beforeCachingHookHash = 'no-hook';
        }

        $tarFilePath = '';

        if (file_exists("$litBasePath/cached-releases/$currentRemoteCommit-$beforeCachingHookHash.tar")) {
            $tarFilePath = "$litBasePath/cached-releases/$currentRemoteCommit-$beforeCachingHookHash.tar";
        } elseif (glob("$litBasePath/cached-releases/$currentRemoteCommit-*.tar")) {
            out("Cached release found but hook changed, rebuilding...\n");
        }

        if ($tarFilePath !== '') {
            out('Reusing deployment from cache');

            // Update timestamp so this cache entry isn't pruned
            touch($tarFilePath);

            $state->currentCommit = $currentRemoteCommit;
        } else {
            $state->tempDirectoryPath = "$litBasePath/cached-releases/wip_".uuid();

            mkdir($state->tempDirectoryPath, 0777, true);

            out('Cloning repository... ');

            $cloneStatusCode = clone_git_ref_into($gitRepositoryUrl, $state->currentRef, $state->currentRefType, $state->tempDirectoryPath);

            if ($cloneStatusCode !== 0) {
                lit_exit($cloneStatusCode);
            }

            out("\n");

            chdir($state->tempDirectoryPath);

            [$revParseStatusCode, $revParseOutput] = run_command_and_capture_stdout(['git', 'rev-parse', 'HEAD']);

            $state->currentCommit = trim($revParseOutput);

            if ($beforeCachingHookPath !== '') {
                $projectBaseName = basename($projectBasePath);

                out("Running \"$projectBaseName/hooks/before-caching.sh\"...\n");

                $hookStatusCode = run_hook($beforeCachingHookPath, [$state->tempDirectoryPath, $projectBasePath, $litBasePath]);

                if ($hookStatusCode !== 0) {
                    lit_exit($hookStatusCode);
                }
            } else {
                out("Wanted to run \"$projectBasePath/hooks/before-caching.sh\" but it does not exist\n");
            }

            $state->stagingDirectoryPath = "$litBasePath/cached-releases/{$state->currentCommit}-$beforeCachingHookHash";
            $tarFilePath = "{$state->stagingDirectoryPath}.tar";

            delete_directory($state->stagingDirectoryPath);

            if (file_exists($tarFilePath)) {
                unlink($tarFilePath);
            }

            chdir("$litBasePath/cached-releases");

            rename($state->tempDirectoryPath, $state->stagingDirectoryPath);

            $zstdIsAvailable = trim((string) shell_exec('command -v zstd 2>/dev/null')) !== '';

            if ($zstdIsAvailable) {
                out('Caching release... ');

                $tarStatusCode = run_command(['tar', '--use-compress-program', 'zstd -T0 -3', '-cf', $tarFilePath, basename($state->stagingDirectoryPath)]);
            } else {
                out('Caching release... (tip: install "zstd" for faster caching)');

                $tarStatusCode = run_command(['tar', '-czf', $tarFilePath, basename($state->stagingDirectoryPath)]);
            }

            if ($tarStatusCode !== 0) {
                lit_exit($tarStatusCode);
            }

            delete_directory($state->stagingDirectoryPath);
        }

        out("\n");
        out("Creating \"{$state->newReleaseDirectory}\" for the new release...\n");

        mkdir($state->newReleaseDirectory);

        $state->releaseDirectoryCreated = true;

        chdir($state->newReleaseDirectory);

        out('Extracting release... ');

        $tarStatusCode = run_command(['tar', '--strip-components=1', '--extract', '--file', $tarFilePath]);

        if ($tarStatusCode !== 0) {
            lit_exit($tarStatusCode);
        }

        out("\n");

        print_recent_commits($state->newReleaseDirectory, $previousCommit);
    } else {
        out("Creating \"{$state->newReleaseDirectory}\" for the new release...\n");

        mkdir($state->newReleaseDirectory);

        $state->releaseDirectoryCreated = true;

        chdir($state->newReleaseDirectory);

        out('Cloning repository... ');

        $cloneStatusCode = clone_git_ref_into($gitRepositoryUrl, $state->currentRef, $state->currentRefType, $state->newReleaseDirectory);

        if ($cloneStatusCode !== 0) {
            lit_exit($cloneStatusCode);
        }

        out("\n");

        [$revParseStatusCode, $revParseOutput] = run_command_and_capture_stdout(['git', 'rev-parse', 'HEAD']);

        $state->currentCommit = trim($revParseOutput);

        print_recent_commits($state->newReleaseDirectory, $previousCommit);
    }
} elseif ($sourceType === 'bundle') {
    $bundleUrl = $litState['bundle_url'];
    $currentBundleHash = $litState['bundle_hash'] ?? 'not deployed yet';

    $cachingEnabled = false;

    // This key is only for git deployments, it should never exist unless the project
    // was incorrectly converted from git to a bundle.
    if (array_key_exists('git_release_caching_enabled', $litState)) {
        unset($litState['git_release_caching_enabled']);

        write_lit_state($projectBasePath, $litState);
    }

    $bundleHashUrl = "$bundleUrl.hash";
    $remoteBundleHashFromHashFile = '';

    if (! is_dir("$litBasePath/cached-releases")) {
        mkdir("$litBasePath/cached-releases", 0777, true);
    }

    // A cached bundle is already the exact deployed bundle, no need to check the remote
    $redeployBundleIsCached = $isRedeploying && file_exists("$litBasePath/cached-releases/$currentBundleHash.tar");

    if (! $redeployBundleIsCached) {
        // To avoid downloading the full bundle, download just a file containing the bundle hash.
        out("Checking bundle version from \"$bundleHashUrl\"... ");

        [$curlStatusCode, $curlResult] = run_command_and_capture(['curl', '--fail', '--silent', '--show-error', '--location', '--write-out', "\n__CURL_TIME__:%{time_total}", $bundleHashUrl]);

        $curlTime = '0';
        $curlOutputLines = [];

        foreach (explode("\n", $curlResult) as $curlLine) {
            if (str_starts_with($curlLine, '__CURL_TIME__:')) {
                $curlTime = substr($curlLine, strlen('__CURL_TIME__:'));
            } else {
                $curlOutputLines[] = $curlLine;
            }
        }

        $curlOutput = trim(implode("\n", $curlOutputLines));

        out(sprintf("(in %.2f seconds)\n", (float) $curlTime));

        if ($curlStatusCode === 0) {
            $remoteBundleHashFromHashFile = preg_replace('/\s+/', '', $curlOutput);

            if (! preg_match('/^[a-fA-F0-9]{40}$/', $remoteBundleHashFromHashFile)) {
                out("Warning: \"$bundleHashUrl\" does not contain a valid SHA1 hash\n");
                out("Hash file contents: $curlOutput\n");

                $remoteBundleHashFromHashFile = '';
            } elseif ($isRedeploying && $currentBundleHash !== $remoteBundleHashFromHashFile) {
                out("The remote bundle (hash: $remoteBundleHashFromHashFile) does not match the deployed bundle (hash: $currentBundleHash)\n");
                out("Cannot redeploy the exact same bundle\n");

                $GLOBALS['current_run_result'] = 'failed (the remote bundle has changed)';

                lit_exit(1);
            } elseif (! $isRedeploying && ! $isForcing && $currentBundleHash === $remoteBundleHashFromHashFile) {
                out("Bundle is already deployed (hash: $remoteBundleHashFromHashFile)\n");
                out("Run \"lit deploy --force\" to redeploy\n");

                $GLOBALS['current_run_result'] = 'aborted, same bundle is already deployed';

                lit_exit(0);
            }
        } else {
            out("Warning: $curlOutput\n");
        }
    }

    $tempBundlePath = "$projectBasePath/bundle-for-current-deployment.tar";

    // Try to find bundle in cache, a redeploy looks for the deployed hash
    $cachedBundleHash = $isRedeploying ? $currentBundleHash : $remoteBundleHashFromHashFile;
    $cachedBundlePath = '';

    if ($cachedBundleHash !== '' && file_exists("$litBasePath/cached-releases/$cachedBundleHash.tar")) {
        $cachedBundlePath = "$litBasePath/cached-releases/$cachedBundleHash.tar";
    }

    if ($cachedBundlePath !== '') {
        out("Using cached bundle (hash: $cachedBundleHash)\n");

        copy($cachedBundlePath, $tempBundlePath);

        touch($cachedBundlePath);

        $state->newBundleHash = $cachedBundleHash;
    } else {
        out("Downloading bundle from \"$bundleUrl\"... ");

        delete_file($tempBundlePath);

        [$curlStatusCode, $curlResult] = run_command_and_capture(['curl', '--fail', '--silent', '--show-error', '--location', '--write-out', "\n__CURL_TIME__:%{time_total}", $bundleUrl, '-o', $tempBundlePath]);

        $curlTime = '0';
        $curlOutputLines = [];

        foreach (explode("\n", $curlResult) as $curlLine) {
            if (str_starts_with($curlLine, '__CURL_TIME__:')) {
                $curlTime = substr($curlLine, strlen('__CURL_TIME__:'));
            } else {
                $curlOutputLines[] = $curlLine;
            }
        }

        if ($curlStatusCode !== 0) {
            out("\n");
            out("Failed to download bundle from \"$bundleUrl\"\n");
            out(trim(implode("\n", $curlOutputLines))."\n");

            $GLOBALS['current_run_result'] = 'failed to download bundle';

            delete_file($tempBundlePath);

            lit_exit(1);
        }

        $bundleSize = human_file_size(filesize($tempBundlePath));

        out(sprintf("($bundleSize in %.2f seconds)\n", (float) $curlTime));

        $state->newBundleHash = sha1_file($tempBundlePath);

        // A redeploy must get the exact deployed bundle back
        if ($isRedeploying && $state->newBundleHash !== $currentBundleHash) {
            out("The downloaded bundle (hash: {$state->newBundleHash}) does not match the deployed bundle (hash: $currentBundleHash)\n");
            out("Cannot redeploy the exact same bundle\n");

            $GLOBALS['current_run_result'] = 'failed (the remote bundle has changed)';

            delete_file($tempBundlePath);

            lit_exit(1);
        }

        if ($remoteBundleHashFromHashFile !== '' && $remoteBundleHashFromHashFile !== $state->newBundleHash) {
            out("Warning: the hash from \"$bundleHashUrl\" does not match the actual hash from \"$bundleUrl\"\n");
            out("Warning: actual bundle hash \"{$state->newBundleHash}\", hash from hash file \"$remoteBundleHashFromHashFile\"\n");
        }

        if (! file_exists("$litBasePath/cached-releases/{$state->newBundleHash}.tar")) {
            out("Adding bundle to cache ($litBasePath/cached-releases/{$state->newBundleHash}.tar)\n");

            copy($tempBundlePath, "$litBasePath/cached-releases/{$state->newBundleHash}.tar");
        } else {
            out("Bundle exists in cache, but using the downloaded bundle instead\n");

            touch("$litBasePath/cached-releases/{$state->newBundleHash}.tar");
        }
    }

    if (! $isRedeploying && $currentBundleHash === $state->newBundleHash) {
        out("Bundle is already deployed (hash: {$state->newBundleHash})\n");

        if ($isForcing) {
            out("Using \"--force\", redeploying...\n");
        } else {
            delete_file($tempBundlePath);

            out("Run \"lit deploy --force\" to redeploy\n");

            $GLOBALS['current_run_result'] = 'aborted, same bundle is already deployed';

            lit_exit(0);
        }
    }

    out("Creating \"{$state->newReleaseDirectory}\" for the new release...\n");

    mkdir($state->newReleaseDirectory);

    $state->releaseDirectoryCreated = true;

    chdir($state->newReleaseDirectory);

    rename($tempBundlePath, "{$state->newReleaseDirectory}/lit-bundle.tar");

    out('Extracting bundle... ');

    // We use "--strip-components=1" so we can use "--exclude-from={file}" when making the bundle, this
    // is the only reliable way to exclude files when making a tar. We don't want to make bundles using
    // "--exclude="node_modules" flags, because those apply to every file/directory with that name, which
    // for example makes it impossible to exclude node_modules in the root of your project, but include
    // the node_modules from your frontend/ directory.
    //
    // The "--warning" flag prevents warnings when the bundle was made on MacOS but extracted on Linux.
    $tarCommand = ['tar', '--strip-components=1', '--extract'];

    if (! is_macos()) {
        $tarCommand[] = '--warning=no-unknown-keyword';
    }

    $tarCommand[] = '--file';
    $tarCommand[] = "{$state->newReleaseDirectory}/lit-bundle.tar";

    $tarStatusCode = run_command($tarCommand);

    if ($tarStatusCode !== 0) {
        lit_exit($tarStatusCode);
    }

    unlink("{$state->newReleaseDirectory}/lit-bundle.tar");

    out("\n");

    // Assuming "config/filesystems.php" is always present. if this file is in the root, then the bundle
    // wasn't made with "--strip-components" in mind.
    if (file_exists("{$state->newReleaseDirectory}/filesystems.php")) {
        out("\n");
        out("Error: Incorrect bundle structure.\n");
        out("All entries in the bundle must be in a top-level directory.\n");
        out("\n");
        out("Run \"tar -tf {bundle}\" to check. Entries should look like:\n");
        out("  ./config/filesystems.php       (good)\n");
        out("  my-app/config/filesystems.php  (good)\n");
        out("  config/filesystems.php         (bad - missing top-level directory)\n");
        out("\n");
        out("See: https://github.com/SjorsO/lit?tab=readme-ov-file#deploying-a-bundle\n");
        out("\n");

        lit_exit(1);
    }
}

// Laravel needs this directory, make sure it exists even if it was excluded from the bundle.
if (! is_dir("{$state->newReleaseDirectory}/bootstrap/cache")) {
    mkdir("{$state->newReleaseDirectory}/bootstrap/cache", 0777, true);
}

out("Creating a symlink to the storage directory\n");

delete_directory("{$state->newReleaseDirectory}/storage");

run_command(['ln', is_macos() ? '-nsf' : '-nsfr', $realStorageDirectoryPath, "{$state->newReleaseDirectory}/storage"]);

out("Creating a symlink to the .env file\n");

delete_file("{$state->newReleaseDirectory}/.env");

run_command(['ln', is_macos() ? '-nsf' : '-nsfr', $realEnvFilePath, "{$state->newReleaseDirectory}/.env"]);

if (! $cachingEnabled && file_exists("$projectBasePath/hooks/before-caching.sh")) {
    out("Hook \"hooks/before-caching.sh\" exists but will not be used because release caching is disabled\n");
}

if (file_exists("$projectBasePath/hooks/before-release.sh")) {
    $hookStatusCode = run_hook("$projectBasePath/hooks/before-release.sh", [$projectBasePath, $state->newReleaseDirectory, $litBasePath, $previousReleaseDirectory]);

    if ($hookStatusCode !== 0) {
        lit_exit($hookStatusCode);
    }
} else {
    out("Wanted to run \"$projectBasePath/hooks/before-release.sh\" but it does not exist\n");
}

out("Releasing the new deployment \"{$state->newReleaseDirectory}\"\n");

// Extracting a cached release can give the release directory an old timestamp.
// Reset the timestamp, pruning uses it to determine the age of a release.
touch($state->newReleaseDirectory);

// Create a symlink to enable the release
run_command(['ln', is_macos() ? '-nsf' : '-nsfr', $state->newReleaseDirectory, $currentDirectoryPath]);

$state->wasReleased = true;

if ($sourceType === 'git') {
    update_lit_state($projectBasePath, 'git_commit_sha', $state->currentCommit);
} elseif ($sourceType === 'bundle') {
    update_lit_state($projectBasePath, 'bundle_hash', $state->newBundleHash);
}

// Remember which .env this release went live with
update_lit_state($projectBasePath, 'deployed_dotenv_hash', sha1_file($realEnvFilePath));

if (file_exists("$projectBasePath/hooks/after-release.sh")) {
    $hookStatusCode = run_hook("$projectBasePath/hooks/after-release.sh", [$projectBasePath, $state->newReleaseDirectory, $litBasePath, $previousReleaseDirectory]);

    if ($hookStatusCode !== 0) {
        lit_exit($hookStatusCode);
    }
} else {
    out("Wanted to run \"$projectBasePath/hooks/after-release.sh\" but it does not exist\n");
}

// Prune old releases: delete any release older than an hour, and keep at most the 6 most recent.
//
// We keep multiple recent releases because quick deployments in a row could otherwise delete a
// release that a long-running job is still referencing.
$releaseIds = array_map('basename', glob("$releasesDirectory/*") ?: []);

// Sanity check, we should never delete something unexpected
foreach ($releaseIds as $releaseId) {
    if (! preg_match('/^[0-9]+$/', $releaseId)) {
        throw new RuntimeException("Unexpected \"$releasesDirectory/$releaseId\", refusing to delete old releases");
    }
}

rsort($releaseIds, SORT_NUMERIC);

// Skip the first id, that is the release we just deployed
foreach (array_slice($releaseIds, 1) as $index => $oldReleaseId) {
    // The new release is not in the list, so ">= 5" means "beyond the 6 most recent"
    $isBeyondSixMostRecent = $index >= 5;
    $isOlderThanAnHour = filemtime("$releasesDirectory/$oldReleaseId") < time() - 60 * 60;

    if ($isOlderThanAnHour || $isBeyondSixMostRecent) {
        out("Deleting old release directory \"$releasesDirectory/$oldReleaseId\"... ");

        delete_directory("$releasesDirectory/$oldReleaseId");

        out("\n");
    }
}

// Prune cached releases older than 7 days, and limit total cache size to 500MB
if (is_dir("$litBasePath/cached-releases")) {
    foreach (glob("$litBasePath/cached-releases/*.tar") ?: [] as $cachedFilePath) {
        if (filemtime($cachedFilePath) < time() - 7 * 24 * 60 * 60) {
            delete_file($cachedFilePath);
        }
    }

    $maxCacheBytes = 500 * 1024 * 1024;

    while (true) {
        clearstatcache();

        $totalCacheBytes = array_sum(array_map(fn ($filePath) => is_file($filePath) ? filesize($filePath) : 0, glob("$litBasePath/cached-releases/*") ?: []));

        if ($totalCacheBytes <= $maxCacheBytes) {
            break;
        }

        $tarFiles = glob("$litBasePath/cached-releases/*.tar") ?: [];

        // Always keep at least 1 cache file
        if (count($tarFiles) <= 1) {
            break;
        }

        usort($tarFiles, fn ($fileA, $fileB) => filemtime($fileA) <=> filemtime($fileB));

        delete_file($tarFiles[0]);
    }
}
