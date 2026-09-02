<?php

// Deploy key helpers, shared by "lit init" and "lit generate-deploy-key"

// Matches "ssh://git@host/repo.git" and the short form "git@host:repo.git"
function is_ssh_git_url(string $url): bool
{
    return preg_match('#^(git\+)?ssh://#', $url) === 1 || preg_match('#^[^/:]+@[^/:]+:#', $url) === 1;
}

// Returns "owner/repo" for a GitHub url, or "" for any other host
function github_repository(string $url): string
{
    if (preg_match('#^(?:\w+://)?(?:[^@/]+@)?github\.com(?::\d+)?[:/]([^/]+/[^/]+?)(?:\.git)?/?$#i', $url, $matches)) {
        return $matches[1];
    }

    return '';
}

// A deploy key only fixes access errors, not a DNS or connection error
function is_git_access_error(string $gitOutput): bool
{
    foreach (['permission denied', 'repository not found', 'could not be found', 'access denied'] as $accessError) {
        if (str_contains(strtolower($gitOutput), $accessError)) {
            return true;
        }
    }

    return false;
}

function generate_deploy_key(string $projectBasePath): void
{
    if (trim((string) shell_exec('command -v ssh-keygen 2>/dev/null')) === '') {
        out("Unable to generate a deploy key, \"ssh-keygen\" is not installed\n");

        lit_exit(1);
    }

    $deployKeyPath = deploy_key_path($projectBasePath);

    // ssh-keygen asks before overwriting, so remove the old key first
    delete_file($deployKeyPath);
    delete_file("$deployKeyPath.pub");

    $comment = 'Lit deploy key for '.basename($projectBasePath).' on '.gethostname();

    [$keygenStatusCode, $keygenOutput] = run_command_and_capture(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', $comment, '-f', $deployKeyPath]);

    if ($keygenStatusCode !== 0) {
        out("Generating a deploy key failed:\n");
        out(trim($keygenOutput)."\n");

        lit_exit($keygenStatusCode);
    }

    out('Generated a deploy key: "'.basename($projectBasePath).'/'.basename($deployKeyPath)."\"\n");
}

// The public key lives next to the private key, rebuild it when it is missing
function read_public_deploy_key(string $projectBasePath): string
{
    $deployKeyPath = deploy_key_path($projectBasePath);

    if (! is_file("$deployKeyPath.pub")) {
        [$keygenStatusCode, $keygenOutput] = run_command_and_capture(['ssh-keygen', '-y', '-f', $deployKeyPath]);

        if ($keygenStatusCode !== 0) {
            out("Reading the deploy key failed:\n");
            out(trim($keygenOutput)."\n");

            lit_exit($keygenStatusCode);
        }

        file_put_contents("$deployKeyPath.pub", trim($keygenOutput)."\n");
    }

    return trim(file_get_contents("$deployKeyPath.pub"));
}

// Prints the title and the key, ready to paste into the deploy key form
function print_deploy_key(string $projectBasePath): void
{
    // A public key line is "type key comment", the comment makes a good title
    $publicKeyParts = explode(' ', read_public_deploy_key($projectBasePath), 3);

    out("Title:\n");
    out("\n");
    out('    '.($publicKeyParts[2] ?? 'Lit deploy key')."\n");
    out("\n");
    out("Deploy key:\n");
    out("\n");
    out('    '.$publicKeyParts[0].' '.($publicKeyParts[1] ?? '')."\n");
}

function print_deploy_key_instructions(string $projectBasePath, string $gitRepositoryUrl): void
{
    $githubRepository = github_repository($gitRepositoryUrl);

    if ($githubRepository !== '') {
        out("Add this deploy key on GitHub:\n");
        out("\n");
        out("    https://github.com/$githubRepository/settings/keys/new\n");
    } else {
        out("Add this deploy key to the repository:\n");
    }

    out("\n");

    print_deploy_key($projectBasePath);
}
