<?php

// Deletes old releases, the release we just deployed is always kept
function prune_old_releases(string $releasesDirectory): void
{
    // PHP < 8.3 does not clear the stat cache on touch(), filemtime() would return stale timestamps
    clearstatcache();

    $releaseIds = array_map('basename', glob("$releasesDirectory/*") ?: []);

    // Sanity check, we should never delete something unexpected
    foreach ($releaseIds as $releaseId) {
        if (! preg_match('/^[0-9]+$/', $releaseId)) {
            throw new RuntimeException("Unexpected \"$releasesDirectory/$releaseId\", refusing to delete old releases");
        }
    }

    rsort($releaseIds, SORT_NUMERIC);

    // Skip the first id, that is the release we just deployed
    foreach (array_slice($releaseIds, 1) as $oldReleaseId) {
        // Prune old releases: delete any release replaced more than an hour ago.
        //
        // We give old releases an hour grace time because a long-running job could still be referencing the
        // old release. Deleting the release while the job is still running causes errors.
        if (filemtime("$releasesDirectory/$oldReleaseId") < time() - 60 * 60) {
            out("Deleting old release directory \"$releasesDirectory/$oldReleaseId\"... ");

            delete_directory("$releasesDirectory/$oldReleaseId");

            out("\n");
        }
    }
}

// Prunes cached releases older than 7 days, and limits total cache size to 500MB
function prune_cached_releases(string $litBasePath): void
{
    if (! is_dir("$litBasePath/cached-releases")) {
        return;
    }

    // Never delete a cache file another project is still using.
    $cacheLock = acquire_cache_lock($litBasePath, isExclusive: true);

    if ($cacheLock === null) {
        out("Another project is using the release cache, skipping the cache cleanup\n");

        return;
    }

    // PHP < 8.3 does not clear the stat cache on touch(), filemtime() would return stale timestamps
    clearstatcache();

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

    release_cache_lock($cacheLock);
}
