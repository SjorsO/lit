<?php

require __DIR__.'/../test-helpers.php';

// Test the "lit generate-deploy-key" command

$worldPath = world_path();
$caseDir = "$worldPath/case";

$seedPath = "$caseDir/seed";
$remotePath = "$caseDir/origin-repo.git";

$gitCommand = ['git', '-c', 'user.email=lit@test', '-c', 'user.name=lit'];

run_process(['git', 'init', '--quiet', '--initial-branch=main', $seedPath], $caseDir);

file_put_contents("$seedPath/app.txt", "one\n");
run_process(['git', 'add', 'app.txt'], $seedPath);
run_process([...$gitCommand, 'commit', '--quiet', '-m', 'one'], $seedPath);

run_process(['git', 'clone', '--quiet', '--bare', $seedPath, $remotePath], $caseDir);

// Fake ssh: without a key "Permission denied", with a key the git command runs locally
mkdir("$worldPath/bin");

file_put_contents("$worldPath/bin/ssh", str_replace('__WORLD__', $worldPath, <<<'SHIM'
#!/bin/bash
printf '%s\n' "$*" >> "__WORLD__/case/ssh-calls.log"

if [[ " $* " != *" -i "* ]]; then
    echo "git@localhost: Permission denied (publickey)." >&2
    exit 255
fi

command="${@: -1}"
eval "git ${command#git-}"
SHIM));

chmod("$worldPath/bin/ssh", 0755);

// The public key and hostname differ on every run
function normalize_deploy_key_output(string $output): string
{
    $output = normalize_output($output);

    $output = preg_replace('/^    ssh-ed25519 \S+$/m', '    ssh-ed25519 PUBKEY', $output);

    return preg_replace('/^    Lit deploy key for (\S+) on .*$/m', '    Lit deploy key for $1 on HOST', $output);
}

// The output shows the key without its comment
function public_key_without_comment(string $publicKeyFile): string
{
    return implode(' ', array_slice(explode(' ', trim(file_get_contents($publicKeyFile))), 0, 2));
}

chdir($caseDir);

// A bundle project has no use for a deploy key
[$statusCode] = lit('init', 'https://example.com/app.tar.gz', 'bundle-project');

assert_same(0, $statusCode);

chdir("$caseDir/bundle-project");

[$statusCode, $output] = lit('generate-deploy-key');

assert_same(1, $statusCode);
assert_same('Deploy keys are only used when deploying from git', $output);

chdir($caseDir);

// Init from a local url, so no key is needed yet
[$statusCode] = lit('init', "file://$remotePath");

assert_same(0, $statusCode);

$projectPath = "$caseDir/origin-repo";

chdir($projectPath);

[$statusCode, $output] = lit('generate-deploy-key', 'extra');

assert_same(1, $statusCode);
assert_same('usage: lit generate-deploy-key', $output);

// 1. Generate a key. The url is not an ssh url, so a note explains the key is not used yet
[$statusCode, $output] = lit_with_input('', [], 'generate-deploy-key');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Generated a deploy key: "origin-repo/deploy-key"

Add this deploy key to the repository:

Title:

    Lit deploy key for origin-repo on HOST

Deploy key:

    ssh-ed25519 PUBKEY

Note: the deploy key is only used with an SSH URL, run "lit init <ssh url>" to switch
EXPECTED, normalize_deploy_key_output($output));

assert_file_exists("$projectPath/deploy-key");
assert_same(0600, fileperms("$projectPath/deploy-key") & 0777);

$firstPublicKey = file_get_contents("$projectPath/deploy-key.pub");

assert_string_contains($output, '    '.public_key_without_comment("$projectPath/deploy-key.pub"));

// 2. With an ssh url, deploying uses the key
set_lit_state_value($projectPath, 'git_repository_url', "git@localhost:$remotePath");

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);
assert_directory_exists("$projectPath/releases/1");

$sshCalls = explode("\n", rtrim(file_get_contents("$caseDir/ssh-calls.log"), "\n"));

// One call to read the branch, one to clone
assert_same(2, count($sshCalls));

foreach ($sshCalls as $sshCall) {
    assert_string_contains($sshCall, "-i $projectPath/deploy-key -o IdentitiesOnly=yes ");
}

// ssh refuses a key that others can read, Lit fixes the permissions before using it
chmod("$projectPath/deploy-key", 0644);

[$statusCode] = lit('deploy', '--force');

assert_same(0, $statusCode);
assert_directory_exists("$projectPath/releases/2");
assert_same(0600, fileperms("$projectPath/deploy-key") & 0777);

// 3. Saying no keeps the key, and shows it again
[$statusCode, $output] = lit_with_input('n', [], 'generate-deploy-key');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
This project already has a deploy key, replace it?
  (●) Yes    ( ) No                                ←/→ + enter to select

Keeping the existing deploy key

Title:

    Lit deploy key for origin-repo on HOST

Deploy key:

    ssh-ed25519 PUBKEY
EXPECTED, normalize_deploy_key_output($output));

assert_file_content("$projectPath/deploy-key.pub", trim($firstPublicKey));

// 4. Saying yes replaces the key
[$statusCode, $output] = lit_with_input('y', [], 'generate-deploy-key');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
This project already has a deploy key, replace it?
  (●) Yes    ( ) No                                ←/→ + enter to select

Generated a deploy key: "origin-repo/deploy-key"

Add this deploy key to the repository:

Title:

    Lit deploy key for origin-repo on HOST

Deploy key:

    ssh-ed25519 PUBKEY

Remember to remove the old deploy key from the repository
EXPECTED, normalize_deploy_key_output($output));

$secondPublicKey = file_get_contents("$projectPath/deploy-key.pub");

if ($secondPublicKey === $firstPublicKey) {
    fail_assertion('Expected a new public key');
}

assert_string_contains($output, '    '.public_key_without_comment("$projectPath/deploy-key.pub"));
assert_same(0600, fileperms("$projectPath/deploy-key") & 0777);

// 5. A GitHub url gets GitHub wording and a link to the deploy key settings
set_lit_state_value($projectPath, 'git_repository_url', 'git@github.com:SjorsO/ottjes.git');

[$statusCode, $output] = lit_with_input('y', [], 'generate-deploy-key');

assert_same(0, $statusCode);
assert_string_contains($output, "Add this deploy key on GitHub:\n\n    https://github.com/SjorsO/ottjes/settings/keys/new\n");
assert_string_contains($output, 'Remember to remove the old deploy key from GitHub');
assert_string_not_contains($output, 'Note:');

// 6. A GitHub https url gets the exact command to switch to ssh
set_lit_state_value($projectPath, 'git_repository_url', 'https://github.com/SjorsO/ottjes.git');

[$statusCode, $output] = lit_with_input('y', [], 'generate-deploy-key');

assert_same(0, $statusCode);
assert_string_contains($output, 'https://github.com/SjorsO/ottjes/settings/keys/new');
assert_string_contains($output, "Note: the deploy key is only used with an SSH URL, switch with:\n  lit init git@github.com:SjorsO/ottjes.git");

// 7. A missing public key file is rebuilt from the private key
$publicKey = file_get_contents("$projectPath/deploy-key.pub");
$publicKeyWithoutComment = public_key_without_comment("$projectPath/deploy-key.pub");

unlink("$projectPath/deploy-key.pub");

[$statusCode, $output] = lit_with_input('n', [], 'generate-deploy-key');

assert_same(0, $statusCode);
assert_string_contains($output, "    $publicKeyWithoutComment");
assert_file_content("$projectPath/deploy-key.pub", trim($publicKey));

// Every run is in the log
$logContent = file_get_contents("$projectPath/logs/lit.log");

assert_string_contains($logContent, 'lit generate-deploy-key extra → failed (invalid usage) (in ');
assert_string_contains($logContent, 'lit generate-deploy-key → generated a deploy key (in ');
assert_string_contains($logContent, 'lit generate-deploy-key → aborted, kept the existing deploy key (in ');
assert_string_contains($logContent, 'lit generate-deploy-key → replaced the deploy key (in ');
