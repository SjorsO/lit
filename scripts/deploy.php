<?php

/**
 * @var string $litBasePath
 * @var string $projectBasePath
 * @var string[] $arguments
 */

$firstOption = $arguments[1] ?? '';
$secondOption = $arguments[2] ?? '';

$currentRemoteCommit = '';

if ($firstOption === '--use-commit-from-checkout') {
    $currentRemoteCommit = $secondOption;
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
$state->currentBranch = '';
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

        if ($state->wasReleased && $exitCode !== 0 && $sourceType === 'git') {
            $result = "had errors, still deployed branch \"{$state->currentBranch}\" (commit: $shortCommit)";
        } elseif ($state->wasReleased && $exitCode !== 0 && $sourceType === 'bundle') {
            $result = "had errors, still deployed bundle (hash: {$state->newBundleHash})";
        } elseif ($state->wasReleased && $sourceType === 'git') {
            $result = "deployed branch \"{$state->currentBranch}\" (commit: $shortCommit)";
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

foreach (glob("$releasesDirectory/*") ?: [] as $releaseDirectoryPath) {
    if (is_dir($releaseDirectoryPath) && ! preg_match('/^[0-9]+$/', basename($releaseDirectoryPath))) {
        out("The name of existing release directory \"$releaseDirectoryPath/\" is not fully numeric, this should never happen\n");

        $GLOBALS['current_run_result'] = 'failed, a release directory has an invalid name';

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
    $state->currentBranch = $litState['git_branch'] ?? '';
    $state->currentCommit = $litState['git_commit'] ?? 'not deployed yet';

    // If we are deploying after a "lit checkout", then we already have the commit.
    if ($currentRemoteCommit === '') {
        out("Reading branch \"{$state->currentBranch}\" of \"$gitRepositoryUrl\"... ");

        [$lsRemoteStatusCode, $lsRemoteOutput] = run_command_and_capture_stdout(['git', 'ls-remote', '--symref', $gitRepositoryUrl, $state->currentBranch]);

        if ($lsRemoteStatusCode !== 0) {
            lit_exit($lsRemoteStatusCode);
        }

        foreach (explode("\n", $lsRemoteOutput) as $lsRemoteLine) {
            if ($lsRemoteLine !== '' && ! str_starts_with($lsRemoteLine, 'ref: refs/heads/')) {
                $currentRemoteCommit = explode("\t", $lsRemoteLine)[0];

                break;
            }
        }

        out("\n");
    }

    if ($state->currentCommit === $currentRemoteCommit) {
        $shortRemoteCommit = substr($currentRemoteCommit, 0, 11);

        out("Latest commit of \"{$state->currentBranch}\" is already deployed ($shortRemoteCommit)\n");

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

            $cloneStatusCode = run_command(['git', 'clone', '--branch', $state->currentBranch, '--depth', '100', '--single-branch', '--quiet', $gitRepositoryUrl, $state->tempDirectoryPath]);

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
    } else {
        out("Creating \"{$state->newReleaseDirectory}\" for the new release...\n");

        mkdir($state->newReleaseDirectory);

        $state->releaseDirectoryCreated = true;

        chdir($state->newReleaseDirectory);

        out('Cloning repository... ');

        $cloneStatusCode = run_command(['git', 'clone', '--branch', $state->currentBranch, '--depth', '100', '--single-branch', '--quiet', $gitRepositoryUrl, $state->newReleaseDirectory]);

        if ($cloneStatusCode !== 0) {
            lit_exit($cloneStatusCode);
        }

        out("\n");

        [$revParseStatusCode, $revParseOutput] = run_command_and_capture_stdout(['git', 'rev-parse', 'HEAD']);

        $state->currentCommit = trim($revParseOutput);
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
        } elseif (! $isForcing && $currentBundleHash === $remoteBundleHashFromHashFile) {
            out("Bundle is already deployed (hash: $remoteBundleHashFromHashFile)\n");
            out("Run \"lit deploy --force\" to redeploy\n");

            $GLOBALS['current_run_result'] = 'aborted, same bundle is already deployed';

            lit_exit(0);
        }
    } else {
        out("Warning: $curlOutput\n");
    }

    $tempBundlePath = "$projectBasePath/bundle-for-current-deployment.tar";

    // Try to find bundle in cache by .hash file hash
    $cachedBundlePath = '';

    if ($remoteBundleHashFromHashFile !== '' && file_exists("$litBasePath/cached-releases/$remoteBundleHashFromHashFile.tar")) {
        $cachedBundlePath = "$litBasePath/cached-releases/$remoteBundleHashFromHashFile.tar";
    }

    if ($cachedBundlePath !== '') {
        out("Using cached bundle (hash: $remoteBundleHashFromHashFile)\n");

        copy($cachedBundlePath, $tempBundlePath);

        touch($cachedBundlePath);

        $state->newBundleHash = $remoteBundleHashFromHashFile;
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

    if ($currentBundleHash === $state->newBundleHash) {
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

// Create a symlink to enable the release
run_command(['ln', is_macos() ? '-nsf' : '-nsfr', $state->newReleaseDirectory, $currentDirectoryPath]);

$state->wasReleased = true;

if ($sourceType === 'git') {
    update_lit_state($projectBasePath, 'git_commit', $state->currentCommit);
} elseif ($sourceType === 'bundle') {
    update_lit_state($projectBasePath, 'bundle_hash', $state->newBundleHash);
}

if (file_exists("$projectBasePath/hooks/after-release.sh")) {
    $hookStatusCode = run_hook("$projectBasePath/hooks/after-release.sh", [$projectBasePath, $state->newReleaseDirectory, $litBasePath, $previousReleaseDirectory]);

    if ($hookStatusCode !== 0) {
        lit_exit($hookStatusCode);
    }
} else {
    out("Wanted to run \"$projectBasePath/hooks/after-release.sh\" but it does not exist\n");
}

// Keep the 6 most recent releases. We need to keep several old releases because multiple quick deployments
// in a row could otherwise delete a release that a long-running job is still referencing.
$releaseIds = array_map('basename', glob("$releasesDirectory/*") ?: []);

rsort($releaseIds, SORT_NUMERIC);

foreach (array_slice($releaseIds, 6) as $oldReleaseDirectory) {
    out("Deleting old release directory \"$releasesDirectory/$oldReleaseDirectory\"... ");

    delete_directory("$releasesDirectory/$oldReleaseDirectory");

    out("\n");
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
