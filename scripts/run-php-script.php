<?php

// Runs a PHP script ($phpSourceFile) inside PHP-FPM by copying it into the public
// directory of the current release and calling it over HTTP via the APP_URL.
// The wrapper that requires this file sets $phpSourceFile, $actionLabel,
// $notIfFirstRelease and $queryString.

$currentReleaseDirectoryPath = "$projectBasePath/current";

if (! is_link($currentReleaseDirectoryPath)) {
    out("Unable to $actionLabel, can not find the current release.\n");

    lit_exit(1);
}

$currentReleaseId = basename(readlink($currentReleaseDirectoryPath));

$previousReleaseDirectoryPath = '';

$releaseIds = array_map('basename', glob("$projectBasePath/releases/*") ?: []);

rsort($releaseIds, SORT_NUMERIC);

foreach ($releaseIds as $releaseId) {
    if ($releaseId !== $currentReleaseId) {
        $previousReleaseDirectoryPath = "$projectBasePath/releases/$releaseId";

        break;
    }
}

if ($previousReleaseDirectoryPath === '' && ($notIfFirstRelease ?? false)) {
    out("Not flushing OPcache because this appears to be the first deployment\n");

    lit_exit(0);
}

if (! file_exists("$projectBasePath/.env")) {
    out("Unable to $actionLabel, no .env file found\n");

    lit_exit(1);
}

$appUrl = '';

foreach (file("$projectBasePath/.env") as $envLine) {
    $envLine = trim(str_replace("\r", '', $envLine));

    if (str_starts_with($envLine, 'APP_URL=')) {
        $appUrl = substr($envLine, strlen('APP_URL='));
        $appUrl = preg_replace('/^["\']/', '', $appUrl);
        $appUrl = preg_replace('/["\']$/', '', $appUrl);
        $appUrl = rtrim($appUrl, '/');
    }
}

if ($appUrl === '') {
    out("Unable to $actionLabel, APP_URL not found in .env file\n");

    lit_exit(1);
}

if (! is_dir("$currentReleaseDirectoryPath/public")) {
    out("Unable to $actionLabel, the current release has no public directory\n");

    lit_exit(1);
}

$scriptFileName = 'lit-'.bin2hex(random_bytes(6)).'.php';
$scriptFilePath = "$currentReleaseDirectoryPath/public/$scriptFileName";

$previousScriptFilePath = '';

if ($previousReleaseDirectoryPath !== '') {
    $previousScriptFilePath = "$previousReleaseDirectoryPath/public/$scriptFileName";
}

$state = new stdClass;
$state->hasCompleted = false;

register_cleanup(function () use ($state, $scriptFilePath, $previousScriptFilePath, $actionLabel, $appUrl) {
    if (file_exists($scriptFilePath)) {
        unlink($scriptFilePath);
    }

    if ($previousScriptFilePath !== '' && file_exists($previousScriptFilePath)) {
        unlink($previousScriptFilePath);
    }

    if (! $state->hasCompleted) {
        out("Failed to $actionLabel. The APP_URL in your .env file is set to \"$appUrl\", is this correct?\n");
    }
});

if (! copy($phpSourceFile, $scriptFilePath)) {
    lit_exit(1);
}

// Best effort: the previous release might not have a public directory
if ($previousScriptFilePath !== '' && is_dir("$previousReleaseDirectoryPath/public")) {
    copy($phpSourceFile, $previousScriptFilePath);
}

$scriptUrl = "$appUrl/$scriptFileName";

if (($queryString ?? '') !== '') {
    $scriptUrl = "$scriptUrl?$queryString";
}

out("Calling \"$appUrl\" to $actionLabel.\n");

$curlStatusCode = run_command(['curl', $scriptUrl, '--silent', '--show-error', '--fail', '--retry', '3', '--max-time', '5', '--retry-max-time', '20']);

if ($curlStatusCode !== 0) {
    lit_exit($curlStatusCode);
}

$state->hasCompleted = true;
