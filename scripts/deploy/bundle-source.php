<?php

// Downloads (or reuses) the bundle, then extracts it into the new release directory
function prepare_bundle_release(stdClass $state, array $litState, string $projectBasePath, string $litBasePath): void
{
    $bundleUrl = $litState['bundle_url'];
    $currentBundleHash = $litState['bundle_hash'] ?? 'not deployed yet';

    $state->cachingEnabled = false;

    // This key is only for git deployments, it should never exist unless the project
    // was incorrectly converted from git to a bundle.
    if (array_key_exists('git_release_caching_enabled', $litState)) {
        unset($litState['git_release_caching_enabled']);

        write_lit_state($projectBasePath, $litState);
    }

    if (! is_dir("$litBasePath/cached-releases") && ! mkdir("$litBasePath/cached-releases", 0777, true)) {
        out("Failed to create \"$litBasePath/cached-releases\"\n");

        lit_exit(1);
    }

    $bundleHashUrl = "$bundleUrl.hash";

    // A cached bundle is already the exact deployed bundle, no need to check the remote
    $redeployBundleIsCached = $state->isRedeploying && file_exists("$litBasePath/cached-releases/$currentBundleHash.tar");

    $remoteBundleHashFromHashFile = $redeployBundleIsCached
        ? ''
        : read_remote_bundle_hash($state, $bundleHashUrl, $currentBundleHash);

    $tempBundlePath = "$projectBasePath/bundle-for-current-deployment.tar";

    fetch_bundle($state, $bundleUrl, $bundleHashUrl, $litBasePath, $tempBundlePath, $currentBundleHash, $remoteBundleHashFromHashFile);

    if (! $state->isRedeploying && $currentBundleHash === $state->newBundleHash) {
        out("Bundle is already deployed (hash: $state->newBundleHash)\n");

        if ($state->isForcing) {
            out("Using \"--force\", redeploying...\n");
        } else {
            delete_file($tempBundlePath);

            out("Run \"lit deploy --force\" to redeploy\n");

            $GLOBALS['current_run_result'] = 'aborted, same bundle is already deployed';

            lit_exit(0);
        }
    }

    extract_bundle($state, $tempBundlePath);
}

// Reads the hash file to avoid downloading a bundle we already have.
// Returns the remote hash, or an empty string when it could not be read.
function read_remote_bundle_hash(stdClass $state, string $bundleHashUrl, string $currentBundleHash): string
{
    // To avoid downloading the full bundle, download just a file containing the bundle hash.
    out("Checking bundle version from \"$bundleHashUrl\"... ");

    [$curlStatusCode, $curlOutput, $curlSeconds] = run_curl_and_capture([$bundleHashUrl]);

    out(sprintf("(in %.2f seconds)\n", $curlSeconds));

    if ($curlStatusCode !== 0) {
        out("Warning: $curlOutput\n");

        return '';
    }

    $remoteBundleHashFromHashFile = preg_replace('/\s+/', '', $curlOutput);

    if (! preg_match('/^[a-fA-F0-9]{40}$/', $remoteBundleHashFromHashFile)) {
        out("Warning: \"$bundleHashUrl\" does not contain a valid SHA1 hash\n");
        out("Hash file contents: $curlOutput\n");

        return '';
    }

    if ($state->isRedeploying && $currentBundleHash !== $remoteBundleHashFromHashFile) {
        out("The remote bundle (hash: $remoteBundleHashFromHashFile) does not match the deployed bundle (hash: $currentBundleHash)\n");
        out("Cannot redeploy the exact same bundle\n");

        $GLOBALS['current_run_result'] = 'failed (the remote bundle has changed)';

        lit_exit(1);
    }

    if (! $state->isRedeploying && ! $state->isForcing && $currentBundleHash === $remoteBundleHashFromHashFile) {
        out("Bundle is already deployed (hash: $remoteBundleHashFromHashFile)\n");
        out("Run \"lit deploy --force\" to redeploy\n");

        $GLOBALS['current_run_result'] = 'aborted, same bundle is already deployed';

        lit_exit(0);
    }

    return $remoteBundleHashFromHashFile;
}

// Puts the bundle at $tempBundlePath, from the cache when possible
function fetch_bundle(stdClass $state, string $bundleUrl, string $bundleHashUrl, string $litBasePath, string $tempBundlePath, string $currentBundleHash, string $remoteBundleHashFromHashFile): void
{
    $cacheLock = acquire_cache_lock($litBasePath, isExclusive: false);

    // Try to find bundle in cache, a redeploy looks for the deployed hash
    $cachedBundleHash = $state->isRedeploying ? $currentBundleHash : $remoteBundleHashFromHashFile;
    $cachedBundlePath = '';

    if ($cachedBundleHash !== '' && file_exists("$litBasePath/cached-releases/$cachedBundleHash.tar")) {
        $cachedBundlePath = "$litBasePath/cached-releases/$cachedBundleHash.tar";
    }

    // Cached files contain their hash in the file name. Double check this hash.
    if ($cachedBundlePath !== '' && sha1_file($cachedBundlePath) !== $cachedBundleHash) {
        out("The cached bundle (hash: $cachedBundleHash) is corrupt, deleting it\n");

        delete_file($cachedBundlePath);

        $cachedBundlePath = '';
    }

    if ($cachedBundlePath !== '') {
        out("Using cached bundle (hash: $cachedBundleHash)\n");

        if (! copy($cachedBundlePath, $tempBundlePath)) {
            out("Failed to copy the cached bundle to \"$tempBundlePath\"\n");

            lit_exit(1);
        }

        touch($cachedBundlePath);

        release_cache_lock($cacheLock);

        $state->newBundleHash = $cachedBundleHash;

        return;
    }

    out("Downloading bundle from \"$bundleUrl\"... ");

    delete_file($tempBundlePath);

    [$curlStatusCode, $curlOutput, $curlSeconds] = run_curl_and_capture([$bundleUrl, '-o', $tempBundlePath]);

    if ($curlStatusCode !== 0) {
        out("\n");
        out("Failed to download bundle from \"$bundleUrl\"\n");
        out("$curlOutput\n");

        $GLOBALS['current_run_result'] = 'failed to download bundle';

        delete_file($tempBundlePath);

        lit_exit(1);
    }

    $bundleSize = human_file_size(filesize($tempBundlePath));

    out(sprintf("($bundleSize in %.2f seconds)\n", $curlSeconds));

    $state->newBundleHash = sha1_file($tempBundlePath);

    // A redeploy must get the exact deployed bundle back
    if ($state->isRedeploying && $state->newBundleHash !== $currentBundleHash) {
        out("The downloaded bundle (hash: $state->newBundleHash) does not match the deployed bundle (hash: $currentBundleHash)\n");
        out("Cannot redeploy the exact same bundle\n");

        $GLOBALS['current_run_result'] = 'failed (the remote bundle has changed)';

        delete_file($tempBundlePath);

        lit_exit(1);
    }

    if ($remoteBundleHashFromHashFile !== '' && $remoteBundleHashFromHashFile !== $state->newBundleHash) {
        out("Warning: the hash from \"$bundleHashUrl\" does not match the actual hash from \"$bundleUrl\"\n");
        out("Warning: actual bundle hash \"$state->newBundleHash\", hash from hash file \"$remoteBundleHashFromHashFile\"\n");
    }

    if (! file_exists("$litBasePath/cached-releases/$state->newBundleHash.tar")) {
        out("Adding bundle to cache ($litBasePath/cached-releases/$state->newBundleHash.tar)\n");

        // Copy to a unique file first, then use an atomic "rename()"
        $state->tempCacheFilePath = "$litBasePath/cached-releases/wip_".uuid().'.building';

        if (! copy($tempBundlePath, $state->tempCacheFilePath) || ! rename($state->tempCacheFilePath, "$litBasePath/cached-releases/$state->newBundleHash.tar")) {
            out("Failed to add the bundle to the cache\n");

            lit_exit(1);
        }

        $state->tempCacheFilePath = '';
    } else {
        out("Bundle exists in cache, but using the downloaded bundle instead\n");

        touch("$litBasePath/cached-releases/$state->newBundleHash.tar");
    }

    release_cache_lock($cacheLock);
}

// Creates the release directory and unpacks the bundle into it
function extract_bundle(stdClass $state, string $tempBundlePath): void
{
    out("Creating \"$state->newReleaseDirectory\" for the new release...\n");

    if (! mkdir($state->newReleaseDirectory)) {
        out("Failed to create \"$state->newReleaseDirectory\"\n");

        lit_exit(1);
    }

    $state->releaseDirectoryCreated = true;

    // Never extract into the wrong directory
    if (! chdir($state->newReleaseDirectory)) {
        out("Failed to enter \"$state->newReleaseDirectory\"\n");

        lit_exit(1);
    }

    if (! rename($tempBundlePath, "$state->newReleaseDirectory/lit-bundle.tar")) {
        out("Failed to move the bundle into \"$state->newReleaseDirectory\"\n");

        lit_exit(1);
    }

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
    $tarCommand[] = "$state->newReleaseDirectory/lit-bundle.tar";

    $tarStatusCode = run_command($tarCommand);

    if ($tarStatusCode !== 0) {
        lit_exit($tarStatusCode);
    }

    unlink("$state->newReleaseDirectory/lit-bundle.tar");

    out("\n");

    // Assuming "config/filesystems.php" is always present. if this file is in the root, then the bundle
    // wasn't made with "--strip-components" in mind.
    if (file_exists("$state->newReleaseDirectory/filesystems.php")) {
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
