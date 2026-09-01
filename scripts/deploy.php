<?php

/**
 * @var string $litBasePath
 * @var string $projectBasePath
 * @var string[] $arguments
 */

require_once __DIR__.'/deploy/cleanup.php';
require_once __DIR__.'/deploy/git-source.php';
require_once __DIR__.'/deploy/bundle-source.php';
require_once __DIR__.'/deploy/prune.php';

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
$realStorageDirectoryPath = is_dir("$projectBasePath/shared/storage")
    ? "$projectBasePath/shared/storage"
    : "$projectBasePath/storage";
$realEnvFilePath = file_exists("$projectBasePath/shared/.env")
    ? "$projectBasePath/shared/.env"
    : "$projectBasePath/.env";

if (! file_exists($realEnvFilePath) || filesize($realEnvFilePath) === 0) {
    touch($realEnvFilePath);

    out("Your \".env\" file is empty, try again when you have filled it in\n");

    $GLOBALS['current_run_result'] = 'aborted, the ".env" file is empty';

    lit_exit(1);
}

$state = (object) [
    'releaseDirectoryCreated' => false,
    'wasReleased' => false,
    'newReleaseDirectory' => '',
    'tempDirectoryPath' => '',
    'tempCacheFilePath' => '',
    'currentRef' => '',
    'currentRefType' => 'branch',
    'currentCommit' => '',
    'newBundleHash' => '',
    'cachingEnabled' => false,
    'isForcing' => $isForcing,
    'isRedeploying' => $isRedeploying,
    'currentRemoteCommit' => $currentRemoteCommit,
];

register_deploy_cleanup($state, $projectBasePath, $sourceType, $startedAt);

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

match ($sourceType) {
    'git' => prepare_git_release($state, $litState, $projectBasePath, $litBasePath),
    'bundle' => prepare_bundle_release($state, $litState, $projectBasePath, $litBasePath),
};

// Laravel needs this directory, make sure it exists even if it was excluded from the bundle.
if (! is_dir("$state->newReleaseDirectory/bootstrap/cache") && ! mkdir("$state->newReleaseDirectory/bootstrap/cache", 0777, true)) {
    out("Failed to create \"$state->newReleaseDirectory/bootstrap/cache\"\n");

    lit_exit(1);
}

out("Creating a symlink to the storage directory\n");

delete_directory("$state->newReleaseDirectory/storage");

$lnStatusCode = run_command(['ln', is_macos() ? '-nsf' : '-nsfr', $realStorageDirectoryPath, "$state->newReleaseDirectory/storage"]);

if ($lnStatusCode !== 0) {
    lit_exit($lnStatusCode);
}

out("Creating a symlink to the .env file\n");

delete_file("$state->newReleaseDirectory/.env");

$lnStatusCode = run_command(['ln', is_macos() ? '-nsf' : '-nsfr', $realEnvFilePath, "$state->newReleaseDirectory/.env"]);

if ($lnStatusCode !== 0) {
    lit_exit($lnStatusCode);
}

if (! $state->cachingEnabled && file_exists("$projectBasePath/hooks/before-caching.sh")) {
    out("Hook \"hooks/before-caching.sh\" exists but will not be used because release caching is disabled\n");
}

if (file_exists("$projectBasePath/hooks/before-release.sh")) {
    $hookStatusCode = run_command(['bash', '-e', "$projectBasePath/hooks/before-release.sh", $projectBasePath, $state->newReleaseDirectory, $litBasePath, $previousReleaseDirectory]);

    if ($hookStatusCode !== 0) {
        lit_exit($hookStatusCode);
    }
} else {
    out("Wanted to run \"$projectBasePath/hooks/before-release.sh\" but it does not exist\n");
}

out("Releasing the new deployment \"$state->newReleaseDirectory\"\n");

// Extracting a cached release can give the release directory an old timestamp.
// Reset the timestamp, pruning uses it to determine the age of a release.
touch($state->newReleaseDirectory);

pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM, SIGHUP], $signalMaskBeforeRelease);

$wasReleased = atomically_replace_symlink('releases/'.basename($state->newReleaseDirectory), $currentDirectoryPath);

if ($wasReleased) {
    $state->wasReleased = true;

    // Touch the previous release to start the 1-hour grace timer.
    if ($previousReleaseDirectory !== '' && is_dir($previousReleaseDirectory)) {
        touch($previousReleaseDirectory);
    }

    match ($sourceType) {
        'git' => update_lit_state($projectBasePath, 'git_commit_sha', $state->currentCommit),
        'bundle' => update_lit_state($projectBasePath, 'bundle_hash', $state->newBundleHash),
    };

    // Remember which .env this release went live with
    update_lit_state($projectBasePath, 'deployed_dotenv_hash', sha1_file($realEnvFilePath));
}

// A pending signal is delivered here, after the release is fully recorded
pcntl_sigprocmask(SIG_SETMASK, $signalMaskBeforeRelease);

if (! $wasReleased) {
    out("Failed to point \"$currentDirectoryPath\" at \"$state->newReleaseDirectory\"\n");

    lit_exit(1);
}

if (file_exists("$projectBasePath/hooks/after-release.sh")) {
    $hookStatusCode = run_command(['bash', '-e', "$projectBasePath/hooks/after-release.sh", $projectBasePath, $state->newReleaseDirectory, $litBasePath, $previousReleaseDirectory]);

    if ($hookStatusCode !== 0) {
        lit_exit($hookStatusCode);
    }
} else {
    out("Wanted to run \"$projectBasePath/hooks/after-release.sh\" but it does not exist\n");
}

prune_old_releases($releasesDirectory);

prune_cached_releases($litBasePath);
