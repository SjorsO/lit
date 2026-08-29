<?php

// Reads HEAD of a fresh clone. A failed read must never reach the lit state,
// an empty commit would make the next deploy think nothing is deployed.
function read_head_commit(string $repositoryPath): string
{
    [$revParseStatusCode, $revParseOutput] = run_command_and_capture_stdout(['git', '-C', $repositoryPath, 'rev-parse', 'HEAD']);

    $commit = trim($revParseOutput);

    if ($revParseStatusCode !== 0 || ! preg_match('/^[0-9a-f]{40}$/', $commit)) {
        out("Failed to read the commit of the new release\n");

        $GLOBALS['current_run_result'] = 'failed (could not read the commit)';

        lit_exit($revParseStatusCode !== 0 ? $revParseStatusCode : 1);
    }

    return $commit;
}

// Resolves the ref, then fills the new release directory from git.
// Uses the release cache when caching is enabled.
function prepare_git_release(stdClass $state, array $litState, string $projectBasePath, string $litBasePath): void
{
    $gitRepositoryUrl = $litState['git_repository_url'];

    $state->currentRef = $litState['git_ref'] ?? '';
    $state->currentRefType = $litState['git_ref_type'] ?? 'branch';
    $state->currentCommit = $litState['git_commit_sha'] ?? 'not deployed yet';

    // Remember the old commit, the commit log draws an arrow from it
    $previousCommit = $litState['git_commit_sha'] ?? '';

    // A redeploy uses the exact commit, even when the ref is a branch or tag
    if ($state->isRedeploying) {
        $state->currentRef = $state->currentRemoteCommit;
        $state->currentRefType = 'commit';
    }

    // If we are deploying after a "lit checkout", then we already have the commit.
    if ($state->currentRemoteCommit === '') {
        if ($state->currentRefType === 'commit') {
            $state->currentRemoteCommit = $state->currentRef;
        } else {
            out("Reading $state->currentRefType \"$state->currentRef\" of \"$gitRepositoryUrl\"... ");

            [$branchCommit, $tagCommit] = resolve_remote_ref($gitRepositoryUrl, $state->currentRef);

            $state->currentRemoteCommit = $state->currentRefType === 'tag' ? $tagCommit : $branchCommit;

            out("\n");

            // Stop right away, deploying an empty commit would fail later at the clone
            if ($state->currentRemoteCommit === '') {
                out(ucfirst($state->currentRefType)." \"$state->currentRef\" does not exist on the remote\n");

                $GLOBALS['current_run_result'] = "failed (the $state->currentRefType does not exist on the remote)";

                lit_exit(1);
            }
        }
    }

    if (! $state->isRedeploying && $state->currentCommit === $state->currentRemoteCommit) {
        $shortRemoteCommit = substr($state->currentRemoteCommit, 0, 11);

        if ($state->currentRefType === 'branch') {
            out("Latest commit of \"$state->currentRef\" is already deployed ($shortRemoteCommit)\n");
        } elseif ($state->currentRefType === 'tag') {
            out("Tag \"$state->currentRef\" is already deployed ($shortRemoteCommit)\n");
        } else {
            out("Commit is already deployed ($shortRemoteCommit)\n");
        }

        if ($state->isForcing) {
            out("Using \"--force\", redeploying...\n");
        } else {
            out("Run \"lit deploy --force\" to redeploy\n");

            $GLOBALS['current_run_result'] = 'aborted, this commit is already deployed';

            lit_exit(0);
        }
    }

    $state->cachingEnabled = ($litState['git_release_caching_enabled'] ?? false) === true;

    if (! $state->cachingEnabled) {
        prepare_git_release_without_cache($state, $gitRepositoryUrl, $previousCommit);

        return;
    }

    prepare_git_release_from_cache($state, $gitRepositoryUrl, $projectBasePath, $litBasePath, $previousCommit);
}

// Clones straight into the new release directory
function prepare_git_release_without_cache(stdClass $state, string $gitRepositoryUrl, string $previousCommit): void
{
    out("Creating \"$state->newReleaseDirectory\" for the new release...\n");

    if (! mkdir($state->newReleaseDirectory)) {
        out("Failed to create \"$state->newReleaseDirectory\"\n");

        lit_exit(1);
    }

    $state->releaseDirectoryCreated = true;

    if (! chdir($state->newReleaseDirectory)) {
        out("Failed to enter \"$state->newReleaseDirectory\"\n");

        lit_exit(1);
    }

    out('Cloning repository... ');

    $cloneStatusCode = clone_git_ref_into($gitRepositoryUrl, $state->currentRef, $state->currentRefType, $state->newReleaseDirectory);

    if ($cloneStatusCode !== 0) {
        lit_exit($cloneStatusCode);
    }

    out("\n");

    $state->currentCommit = read_head_commit($state->newReleaseDirectory);

    print_recent_commits($state->newReleaseDirectory, $previousCommit);
}

// Builds a cached tar (or reuses one), then extracts it into the new release directory
function prepare_git_release_from_cache(stdClass $state, string $gitRepositoryUrl, string $projectBasePath, string $litBasePath, string $previousCommit): void
{
    if (is_link("$projectBasePath/hooks/before-caching.sh")) {
        $beforeCachingHookPath = realpath("$projectBasePath/hooks/before-caching.sh");
        $beforeCachingHookHash = substr(sha1_file($beforeCachingHookPath), 0, 12);
    } elseif (file_exists("$projectBasePath/hooks/before-caching.sh")) {
        $beforeCachingHookPath = "$projectBasePath/hooks/before-caching.sh";
        $beforeCachingHookHash = substr(sha1_file($beforeCachingHookPath), 0, 12);
    } else {
        $beforeCachingHookPath = '';
        $beforeCachingHookHash = 'no-hook';
    }

    // The ref is part of the cache key: two refs can point at the same commit
    // but produce different clones, since ".git" records the ref (e.g. a branch
    // head vs. a tag on the same commit).
    $cacheCommit = substr($state->currentRemoteCommit, 0, 12);
    $cacheRefHash = substr(sha1("$state->currentRefType:$state->currentRef"), 0, 12);

    $tarFilePath = '';

    if (file_exists("$litBasePath/cached-releases/$cacheCommit-$cacheRefHash-$beforeCachingHookHash.tar")) {
        $tarFilePath = "$litBasePath/cached-releases/$cacheCommit-$cacheRefHash-$beforeCachingHookHash.tar";
    } elseif (glob("$litBasePath/cached-releases/$cacheCommit-$cacheRefHash-*.tar")) {
        out("Cached release found but hook changed, rebuilding...\n");
    } elseif (glob("$litBasePath/cached-releases/$cacheCommit-*.tar")) {
        out("Cached release found but for a different ref, rebuilding...\n");
    }

    if ($tarFilePath !== '') {
        out('Reusing deployment from cache');

        // Update timestamp so this cache entry isn't pruned
        touch($tarFilePath);

        $state->currentCommit = $state->currentRemoteCommit;
    } else {
        $tarFilePath = build_cached_release($state, $gitRepositoryUrl, $projectBasePath, $litBasePath, $beforeCachingHookPath, $beforeCachingHookHash, $cacheRefHash);
    }

    out("\n");
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

    out('Extracting release... ');

    $tarStatusCode = run_command(['tar', '--strip-components=1', '--extract', '--file', $tarFilePath]);

    if ($tarStatusCode !== 0) {
        lit_exit($tarStatusCode);
    }

    out("\n");

    print_recent_commits($state->newReleaseDirectory, $previousCommit);
}

// Clones, runs the before-caching hook, and tars the result. Returns the tar path.
function build_cached_release(stdClass $state, string $gitRepositoryUrl, string $projectBasePath, string $litBasePath, string $beforeCachingHookPath, string $beforeCachingHookHash, string $cacheRefHash): string
{
    $state->tempDirectoryPath = "$litBasePath/cached-releases/wip_".uuid();

    if (! mkdir($state->tempDirectoryPath, 0777, true)) {
        out("Failed to create \"$state->tempDirectoryPath\"\n");

        lit_exit(1);
    }

    out('Cloning repository... ');

    $cloneStatusCode = clone_git_ref_into($gitRepositoryUrl, $state->currentRef, $state->currentRefType, $state->tempDirectoryPath);

    if ($cloneStatusCode !== 0) {
        lit_exit($cloneStatusCode);
    }

    out("\n");

    if (! chdir($state->tempDirectoryPath)) {
        out("Failed to enter \"$state->tempDirectoryPath\"\n");

        lit_exit(1);
    }

    $state->currentCommit = read_head_commit($state->tempDirectoryPath);

    if ($beforeCachingHookPath !== '') {
        $projectBaseName = basename($projectBasePath);

        out("Running \"$projectBaseName/hooks/before-caching.sh\"...\n");

        $hookStatusCode = run_command(['bash', '-e', $beforeCachingHookPath, $state->tempDirectoryPath, $projectBasePath, $litBasePath]);

        if ($hookStatusCode !== 0) {
            lit_exit($hookStatusCode);
        }
    } else {
        out("Wanted to run \"$projectBasePath/hooks/before-caching.sh\" but it does not exist\n");
    }

    $state->stagingDirectoryPath = "$litBasePath/cached-releases/".substr($state->currentCommit, 0, 12)."-$cacheRefHash-$beforeCachingHookHash";
    $tarFilePath = "$state->stagingDirectoryPath.tar";

    delete_directory($state->stagingDirectoryPath);

    if (file_exists($tarFilePath)) {
        unlink($tarFilePath);
    }

    if (! chdir("$litBasePath/cached-releases")) {
        out("Failed to enter \"$litBasePath/cached-releases\"\n");

        lit_exit(1);
    }

    if (! rename($state->tempDirectoryPath, $state->stagingDirectoryPath)) {
        out("Failed to move the clone to \"$state->stagingDirectoryPath\"\n");

        lit_exit(1);
    }

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

    return $tarFilePath;
}
