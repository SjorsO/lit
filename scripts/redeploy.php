<?php

/**
 * @var string $litBasePath
 * @var string $projectBasePath
 * @var string[] $arguments
 */

if (isset($arguments[1])) {
    out("usage: lit redeploy\n");

    $GLOBALS['current_run_result'] = 'failed (invalid usage)';

    lit_exit(1);
}

$sourceType = get_source_type($projectBasePath);

$litState = read_lit_state($projectBasePath);

if ($sourceType === 'git') {
    $deployedHash = $litState['git_commit_sha'] ?? '';
} elseif ($sourceType === 'bundle') {
    $deployedHash = $litState['bundle_hash'] ?? '';
} else {
    // This should never happen unless files were manually tampered with.
    out("Invalid source type: \"$sourceType\"\n");

    $GLOBALS['current_run_result'] = 'failed (invalid source type)';

    lit_exit(1);
}

if (! preg_match('/^[0-9a-f]{40}$/', $deployedHash)) {
    out("Nothing is deployed yet, run \"lit deploy\" first\n");

    $GLOBALS['current_run_result'] = 'aborted, nothing is deployed yet';

    lit_exit(1);
}

if ($sourceType === 'git') {
    out('Redeploying the current commit ('.substr($deployedHash, 0, 11).")\n");
} else {
    out("Redeploying the current bundle (hash: $deployedHash)\n");
}

$arguments = ['deploy', '--redeploy', $deployedHash];

require "$litBasePath/scripts/deploy.php";
