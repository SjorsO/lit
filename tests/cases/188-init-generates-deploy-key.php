<?php

require __DIR__.'/../test-helpers.php';

// When "lit init" can't access a repository over ssh, it offers to generate a deploy
// key, shows the public key, and tries again once the key is added.
// A fake ssh (in the world "bin" directory) plays the git server, so the test
// never touches the network.

$worldPath = world_path();
$caseDir = "$worldPath/case";

$seedPath = "$caseDir/seed";
$remotePath = "$caseDir/origin-repo.git";
$remoteUrl = "git@localhost:$remotePath";

$gitCommand = ['git', '-c', 'user.email=lit@test', '-c', 'user.name=lit'];

run_process(['git', 'init', '--quiet', '--initial-branch=main', $seedPath], $caseDir);

file_put_contents("$seedPath/app.txt", "one\n");
run_process(['git', 'add', 'app.txt'], $seedPath);
run_process([...$gitCommand, 'commit', '--quiet', '-m', 'one'], $seedPath);

run_process(['git', 'clone', '--quiet', '--bare', $seedPath, $remotePath], $caseDir);

// The fake ssh behaves like GitHub:
// - without a key: "Permission denied"
// - with a key it has never seen: "Repository not found" (the key is not added yet)
// - with a key it has seen before: runs the git command locally, like a real server
mkdir("$worldPath/bin");

file_put_contents("$worldPath/bin/ssh", str_replace('__WORLD__', $worldPath, <<<'SHIM'
#!/bin/bash
printf '%s\n' "$*" >> "__WORLD__/case/ssh-calls.log"

if [[ "$*" == *"@refused.test "* ]]; then
    echo "ssh: connect to host refused.test port 22: Connection refused" >&2
    exit 255
fi

if [[ " $* " != *" -i "* ]]; then
    echo "git@localhost: Permission denied (publickey)." >&2
    exit 255
fi

if [ ! -f "__WORLD__/case/key-was-added" ]; then
    touch "__WORLD__/case/key-was-added"
    echo "ERROR: Repository not found." >&2
    exit 255
fi

# The last argument is the command, e.g. git-upload-pack '/path/repo.git'
command="${@: -1}"
eval "git ${command#git-}"
SHIM));

chmod("$worldPath/bin/ssh", 0755);

function ssh_calls(): array
{
    return explode("\n", rtrim(file_get_contents(world_path().'/case/ssh-calls.log'), "\n"));
}

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

// 1. Say yes to the deploy key, press enter too early (the key is not added yet),
// then press enter again once it is added
[$statusCode, $output] = lit_with_input("y\n\n", [], 'init', $remoteUrl);

assert_same(0, $statusCode);

assert_same(<<<EXPECTED
Reading "$remoteUrl"... git@localhost: Permission denied (publickey).
fatal: Could not read from remote repository.

Please make sure you have the correct access rights
and the repository exists.

Generate a deploy key?
  (●) Yes    ( ) No                                ←/→ + enter to select

Generated a deploy key: "origin-repo/deploy-key"

Add this deploy key to the repository:

Title:

    Lit deploy key for origin-repo on HOST

Deploy key:

    ssh-ed25519 PUBKEY

  Press enter to try again once you have added the key         q to quit

Reading "$remoteUrl"... ERROR: Repository not found.
fatal: Could not read from remote repository.

Please make sure you have the correct access rights
and the repository exists.

The deploy key "origin-repo/deploy-key" has no access to the repository

Add this deploy key to the repository:

Title:

    Lit deploy key for origin-repo on HOST

Deploy key:

    ssh-ed25519 PUBKEY

  Press enter to try again once you have added the key         q to quit

Reading "$remoteUrl"... Done!

Current branch set to "main"

Finished initializing "origin-repo"

Next steps:
- cd "origin-repo"
- Fill in the ".env" file
- Review these newly created hooks:
  - "hooks/before-release.sh"
  - "hooks/after-release.sh"
  - "hooks/on-failure.sh"

After that, either:
- Run "lit deploy" to deploy the current branch (main)
- Run "lit checkout <branch|tag|commit>" to deploy something else
EXPECTED, normalize_deploy_key_output($output));

$projectPath = "$caseDir/origin-repo";

assert_file_exists("$projectPath/deploy-key");
assert_file_exists("$projectPath/deploy-key.pub");
assert_file_exists("$projectPath/lit.json");

// Only the owner may read the private key
assert_same(0600, fileperms("$projectPath/deploy-key") & 0777);

// The public key file matches the key that was shown
assert_string_contains($output, '    '.public_key_without_comment("$projectPath/deploy-key.pub"));

// The first attempt ran without a key, every attempt after that used the deploy key
// (the last call is the ".env.example" lookup)
$sshCalls = ssh_calls();

assert_same(4, count($sshCalls));
assert_string_not_contains($sshCalls[0], '-i ');

foreach (array_slice($sshCalls, 1) as $sshCall) {
    assert_string_contains($sshCall, "-i $projectPath/deploy-key -o IdentitiesOnly=yes ");
}

// 2. Deploying uses the deploy key too
neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);
assert_directory_exists("$projectPath/releases/1");
assert_file_content("$projectPath/releases/1/app.txt", 'one');

$sshCalls = ssh_calls();

// One call to read the branch, one to clone
assert_same(6, count($sshCalls));

foreach (array_slice($sshCalls, 4) as $sshCall) {
    assert_string_contains($sshCall, "-i $projectPath/deploy-key -o IdentitiesOnly=yes ");
}

chdir($caseDir);

// 3. Saying no leaves nothing behind, and exits with the status code of git
[$statusCode, $output] = lit_with_input('n', [], 'init', $remoteUrl, 'second');

assert_same(128, $statusCode);

assert_same(<<<EXPECTED
Reading "$remoteUrl"... git@localhost: Permission denied (publickey).
fatal: Could not read from remote repository.

Please make sure you have the correct access rights
and the repository exists.

Generate a deploy key?
  (●) Yes    ( ) No                                ←/→ + enter to select
EXPECTED, normalize_output($output));

assert_file_missing("$caseDir/second");

// 4. A connection error is not fixed by a deploy key, so no menu is shown
[$statusCode, $output] = lit_with_input('', [], 'init', 'git@refused.test:foo/bar.git');

assert_same(128, $statusCode);
assert_string_contains($output, 'Connection refused');
assert_string_not_contains($output, 'Generate a deploy key');
assert_file_missing("$caseDir/bar");

// 5. Quitting after the key is generated keeps the key, and says how to continue
[$statusCode, $output] = lit_with_input('y', [], 'init', $remoteUrl, 'third');

assert_same(130, $statusCode);

assert_same(<<<EXPECTED
Reading "$remoteUrl"... git@localhost: Permission denied (publickey).
fatal: Could not read from remote repository.

Please make sure you have the correct access rights
and the repository exists.

Generate a deploy key?
  (●) Yes    ( ) No                                ←/→ + enter to select

Generated a deploy key: "third/deploy-key"

Add this deploy key to the repository:

Title:

    Lit deploy key for third on HOST

Deploy key:

    ssh-ed25519 PUBKEY

  Press enter to try again once you have added the key         q to quit

Run "lit init $remoteUrl third" again once you have added the key
EXPECTED, normalize_deploy_key_output($output));

// Only the key is in the directory
assert_same(['deploy-key', 'deploy-key.pub'], array_values(array_diff(scandir("$caseDir/third"), ['.', '..'])));
assert_file_missing("$caseDir/third/lit.json");

// Running init again is allowed in a directory that only holds a deploy key,
// and the existing key is used right away
[$statusCode, $output] = lit_with_input('', [], 'init', $remoteUrl, 'third');

assert_same(0, $statusCode);
assert_string_contains($output, "Reading \"$remoteUrl\"... Done!");
assert_string_not_contains($output, 'Generate a deploy key');
assert_file_exists("$caseDir/third/lit.json");

$sshCalls = ssh_calls();

assert_string_contains(end($sshCalls), "-i $caseDir/third/deploy-key -o IdentitiesOnly=yes ");
