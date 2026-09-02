<?php

// Unit checks for the url helpers behind deploy keys.
// This file does not use test-helpers.php on purpose: it loads the real
// lit helpers, and both files define is_macos().

require getenv('LIT_WORLD_PATH').'/lit/scripts/helpers.php';
require getenv('LIT_WORLD_PATH').'/lit/scripts/deploy-key.php';

function check(bool $condition, string $message): void
{
    if (! $condition) {
        echo "Failed: $message\n";

        exit(1);
    }
}

// GitHub urls in every form git accepts
foreach ([
    'git@github.com:SjorsO/ottjes.git',
    'git@github.com:SjorsO/ottjes',
    'ssh://git@github.com/SjorsO/ottjes.git',
    'ssh://git@github.com:22/SjorsO/ottjes.git',
    'https://github.com/SjorsO/ottjes.git',
    'https://github.com/SjorsO/ottjes',
    'https://github.com/SjorsO/ottjes/',
    'https://GitHub.com/SjorsO/ottjes.git',
] as $url) {
    check(github_repository($url) === 'SjorsO/ottjes', "github_repository($url)");
}

// Not GitHub, or not a repository
foreach ([
    'https://notgithub.com/SjorsO/ottjes.git',
    'https://github.com.evil.test/SjorsO/ottjes.git',
    'git@gitlab.com:SjorsO/ottjes.git',
    'https://github.com/SjorsO',
    'file:///tmp/repo.git',
    '',
] as $url) {
    check(github_repository($url) === '', "github_repository($url) should be empty");
}

foreach ([
    'git@github.com:SjorsO/ottjes.git',
    'deploy@example.com:repo.git',
    'ssh://git@github.com/SjorsO/ottjes.git',
    'git+ssh://git@github.com/SjorsO/ottjes.git',
] as $url) {
    check(is_ssh_git_url($url), "is_ssh_git_url($url)");
}

foreach ([
    'https://github.com/SjorsO/ottjes.git',
    'git://github.com/SjorsO/ottjes.git',
    'file:///tmp/repo.git',
    '/local/path/repo.git',
    'https://user@example.com/repo.git',
] as $url) {
    check(! is_ssh_git_url($url), "is_ssh_git_url($url) should be false");
}

// Errors that a deploy key fixes
foreach ([
    "git@github.com: Permission denied (publickey).\nfatal: Could not read from remote repository.",
    "ERROR: Repository not found.\nfatal: Could not read from remote repository.",
    "GitLab: The project you were looking for could not be found or you don't have permission to view it.",
    'repository access denied. access via a deployment key is read-only.',
] as $gitError) {
    check(is_git_access_error($gitError), "is_git_access_error: $gitError");
}

// Errors that a deploy key does not fix
foreach ([
    'ssh: Could not resolve hostname github.com: nodename nor servname provided, or not known',
    'ssh: connect to host github.com port 22: Connection refused',
    "Host key verification failed.\nfatal: Could not read from remote repository.",
    '',
] as $gitError) {
    check(! is_git_access_error($gitError), "is_git_access_error should be false: $gitError");
}

echo "All checks passed\n";
