<?php

/**
 * @var string $litBasePath
 * @var string $projectBasePath
 * @var string[] $arguments
 */

require_once __DIR__.'/deploy-key.php';

if (isset($arguments[1])) {
    out("usage: lit generate-deploy-key\n");

    $GLOBALS['current_run_result'] = 'failed (invalid usage)';

    lit_exit(1);
}

if (get_source_type($projectBasePath) !== 'git') {
    out("Deploy keys are only used when deploying from git\n");

    $GLOBALS['current_run_result'] = 'failed (not a git project)';

    lit_exit(1);
}

$gitRepositoryUrl = read_lit_state($projectBasePath)['git_repository_url'];

$hadDeployKey = is_file(deploy_key_path($projectBasePath));

if ($hadDeployKey) {
    out("This project already has a deploy key, replace it?\n");

    if (yes_no_menu() === 'n') {
        out("\n");
        out("Keeping the existing deploy key:\n");
        out(read_public_deploy_key($projectBasePath)."\n");

        $GLOBALS['current_run_result'] = 'aborted, kept the existing deploy key';

        lit_exit(0);
    }

    out("\n");
}

generate_deploy_key($projectBasePath);

out("\n");

print_deploy_key_instructions($projectBasePath, $gitRepositoryUrl);

$githubRepository = github_repository($gitRepositoryUrl);

if ($hadDeployKey) {
    out("\n");
    out('Remember to remove the old deploy key from '.($githubRepository !== '' ? 'GitHub' : 'the repository')."\n");
}

// Git only uses the key for ssh, so warn when the url is https (or something else)
if (! is_ssh_git_url($gitRepositoryUrl)) {
    out("\n");

    if ($githubRepository !== '') {
        out("Note: the deploy key is only used with an SSH URL, switch with:\n");
        out("  lit init git@github.com:$githubRepository.git\n");
    } else {
        out("Note: the deploy key is only used with an SSH URL, run \"lit init <ssh url>\" to switch\n");
    }
}

$GLOBALS['current_run_result'] = $hadDeployKey ? 'replaced the deploy key' : 'generated a deploy key';
