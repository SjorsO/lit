<?php

/**
 * @var string $litBasePath
 * @var string $projectBasePath
 * @var string[] $arguments
 */

$newRef = $arguments[1] ?? '';

if ($newRef === '' || isset($arguments[2])) {
    out("usage: lit checkout <branch|tag|commit>\n");

    $GLOBALS['current_run_result'] = 'failed (invalid usage)';

    lit_exit(1);
}

$sourceType = get_source_type($projectBasePath);

if ($sourceType !== 'git') {
    out("Cannot checkout because you are not deploying from git\n");

    $GLOBALS['current_run_result'] = 'failed (not a git project)';

    lit_exit(1);
}

$litState = read_lit_state($projectBasePath);

$gitRepositoryUrl = $litState['git_repository_url'];
$currentRef = $litState['git_ref'] ?? '';

if ($currentRef === $newRef) {
    out("\"$newRef\" is already checked out\n");

    $GLOBALS['current_run_result'] = 'aborted, already checked out';

    lit_exit(1);
}

out("Switching to \"$newRef\"... ");

[$branchCommit, $tagCommit] = resolve_remote_ref($gitRepositoryUrl, $newRef);

out("\n");

// Same resolve order as "git checkout": branch first, then tag, then commit
if ($branchCommit !== '') {
    $newRefType = 'branch';
    $currentRemoteCommit = $branchCommit;

    if ($tagCommit !== '') {
        out("Warning: \"$newRef\" is both a branch and a tag, using the branch\n");
    }
} elseif ($tagCommit !== '') {
    $newRefType = 'tag';
    $currentRemoteCommit = $tagCommit;
} elseif (preg_match('/^[0-9a-f]{40}$/i', $newRef)) {
    $newRef = strtolower($newRef);

    $newRefType = 'commit';
    $currentRemoteCommit = $newRef;
} elseif (preg_match('/^[0-9a-f]{7,39}$/i', $newRef)) {
    $newRef = strtolower($newRef);

    out("Resolving short hash \"$newRef\"... ");

    [$expandStatus, $fullCommit] = expand_short_commit($gitRepositoryUrl, $litBasePath, $newRef);

    if ($expandStatus === 'ambiguous') {
        out("\n");
        out("Short hash \"$newRef\" is ambiguous on the remote, use more characters\n");

        $GLOBALS['current_run_result'] = 'failed (ambiguous short hash)';

        lit_exit(1);
    }

    if ($expandStatus === 'not-found') {
        out("\n");
        out("\"$newRef\" is not a branch, tag, or commit on the remote\n");

        $GLOBALS['current_run_result'] = 'failed (ref does not exist)';

        lit_exit(1);
    }

    out("($fullCommit)\n");

    $newRef = $fullCommit;
    $newRefType = 'commit';
    $currentRemoteCommit = $fullCommit;
} else {
    out("\"$newRef\" is not a branch, tag, or commit on the remote\n");

    $GLOBALS['current_run_result'] = 'failed (ref does not exist)';

    lit_exit(1);
}

$litState['git_ref'] = $newRef;
$litState['git_ref_type'] = $newRefType;

write_lit_state($projectBasePath, $litState);

$arguments = ['deploy', '--use-commit-from-checkout', $currentRemoteCommit];

require "$litBasePath/scripts/deploy.php";
