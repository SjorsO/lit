<?php

/**
 * @var string $litBasePath
 * @var string $projectBasePath
 * @var string[] $arguments
 */

$newBranch = $arguments[1] ?? '';

if ($newBranch === '' || isset($arguments[2])) {
    out("usage: lit checkout <branch>\n");

    $GLOBALS['current_run_result'] = 'failed (invalid usage)';

    lit_exit(1);
}

$sourceType = get_source_type($projectBasePath);

if ($sourceType !== 'git') {
    out("Cannot change branches because you are not deploying from git\n");

    $GLOBALS['current_run_result'] = 'failed (not a git project)';

    lit_exit(1);
}

$gitRepositoryUrl = get_file_value("$projectBasePath/git-repository-url");
$currentBranch = get_file_value("$projectBasePath/git-branch");

if ($currentBranch === $newBranch) {
    out("Current branch is already \"$newBranch\"\n");

    $GLOBALS['current_run_result'] = 'aborted, already on this branch';

    lit_exit(1);
}

out("Switching to branch \"$newBranch\"... ");

[$lsRemoteStatusCode, $remoteBranchInfo] = run_command_and_capture_stdout(['git', 'ls-remote', '--symref', $gitRepositoryUrl, $newBranch]);

if ($lsRemoteStatusCode !== 0) {
    lit_exit($lsRemoteStatusCode);
}

out("\n");

if (trim($remoteBranchInfo) === '') {
    out("Branch \"$newBranch\" does not exist on remote\n");

    $GLOBALS['current_run_result'] = 'failed (branch does not exist)';

    lit_exit(1);
}

$currentRemoteCommit = '';

foreach (explode("\n", $remoteBranchInfo) as $lsRemoteLine) {
    if ($lsRemoteLine !== '' && ! str_starts_with($lsRemoteLine, 'ref: refs/heads/')) {
        $currentRemoteCommit = explode("\t", $lsRemoteLine)[0];

        break;
    }
}

file_put_contents("$projectBasePath/git-branch", "$newBranch\n");

$arguments = ['deploy', '--use-commit-from-checkout', $currentRemoteCommit];

require "$litBasePath/scripts/deploy.php";
