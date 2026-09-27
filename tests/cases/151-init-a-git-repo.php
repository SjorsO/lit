<?php

require __DIR__.'/../test-helpers.php';

[$statusCode, $output] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/lit";

// Assert directories exist
assert_directory_exists("$projectPath/storage");
assert_directory_exists("$projectPath/hooks");
assert_directory_exists("$projectPath/releases");

// Assert lit.json has correct content
assert_file_content("$projectPath/lit.json", <<<'EXPECTED'
{
    "git_repository_url": "https://github.com/SjorsO/lit.git",
    "git_ref": "main",
    "git_ref_type": "branch",
    "git_commit_sha": "not deployed yet",
    "git_release_caching_enabled": false
}
EXPECTED);

// Assert hooks are copied from the git stubs
assert_files_match("$projectPath/hooks/before-release.sh", "$worldPath/lit/stubs/hooks-for-git/before-release.sh.stub");
assert_files_match("$projectPath/hooks/after-release.sh", "$worldPath/lit/stubs/hooks-for-git/after-release.sh.stub");
assert_files_match("$projectPath/hooks/on-failure.sh", "$worldPath/lit/stubs/on-failure.sh.stub");

// Assert .env file exists and is empty
assert_file_exists("$projectPath/.env");
assert_file_content("$projectPath/.env", '');

// This repository has no ".env.example", so nothing is copied and no key is set
assert_string_not_contains($output, 'Created ".env" from the ".env.example" in the repository');
assert_string_not_contains($output, 'Application key (APP_KEY) set successfully.');

// The temporary clone directory is cleaned up
assert_file_missing("$projectPath/env-example-clone");

// Assert current symlink doesn't exist yet (created after first deployment)
assert_file_missing("$projectPath/current");

// Assert before-caching hook isn't created (only created when caching is enabled)
assert_file_missing("$projectPath/hooks/before-caching.sh");

// Init a repo that has a ".env.example", the ".env" should be created from it.
// Uses a local git repository as the remote. The test controls the files in it.
$caseDir = "$worldPath/case";

$seedPath = "$caseDir/seed";
$remotePath = "$caseDir/env-example-repo.git";

// "file://" makes git use the real transport, a plain path would ignore "--depth" and "--filter"
$remoteUrl = "file://$remotePath";

$gitCommand = ['git', '-c', 'user.email=lit@test', '-c', 'user.name=lit'];

$envExample = <<<'ENV'
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=sqlite
# DB_HOST=127.0.0.1
# DB_PORT=3306

ENV;

run_process(['git', 'init', '--quiet', '--initial-branch=main', $seedPath], $caseDir);

// A ".env.example" plus some other Laravel files that must not be checked out
file_put_contents("$seedPath/.env.example", $envExample);
file_put_contents("$seedPath/composer.json", "{}\n");
file_put_contents("$seedPath/artisan", "#!/usr/bin/env php\n");
run_process(['git', 'add', '-A'], $seedPath);
run_process([...$gitCommand, 'commit', '--quiet', '-m', 'first commit'], $seedPath);

run_process(['git', 'clone', '--quiet', '--bare', $seedPath, $remotePath], $caseDir);

// Lit clones with "--filter=blob:none" so only the ".env.example" blob is fetched.
// The remote must allow that filter (GitHub and GitLab do). Without this setting
// git prints "filtering not recognized by server" and fetches every blob instead.
run_process(['git', '-C', $remotePath, 'config', 'uploadpack.allowFilter', 'true'], $caseDir);

[$statusCode, $output] = lit('init', $remoteUrl);

assert_same(0, $statusCode);
assert_string_contains($output, 'Created ".env" from the ".env.example" in the repository');
assert_string_contains($output, 'Application key (APP_KEY) set successfully.');

$envExampleProjectPath = "$caseDir/env-example-repo";

$envContents = file_get_contents("$envExampleProjectPath/.env");

// The rest of the ".env.example" is copied over as-is
assert_string_contains($envContents, "APP_NAME=Laravel\n");
assert_string_contains($envContents, "APP_URL=http://localhost:8000\n");
assert_string_contains($envContents, "DB_CONNECTION=sqlite\n");

// The empty "APP_KEY=" is filled in with a generated key (base64 of 32 random bytes)
assert_string_not_contains($envContents, "APP_KEY=\n");
assert_matches('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m', $envContents);

// Apart from the key, the ".env" is an exact copy of the ".env.example"
preg_match('/^APP_KEY=(.*)$/m', $envContents, $appKeyMatches);
assert_same(str_replace("APP_KEY=\n", "APP_KEY={$appKeyMatches[1]}\n", $envExample), $envContents);

// The temporary clone directory is cleaned up
assert_file_missing("$envExampleProjectPath/env-example-clone");

// None of the other repository files are checked out
assert_file_missing("$envExampleProjectPath/.env.example");
assert_file_missing("$envExampleProjectPath/composer.json");
assert_file_missing("$envExampleProjectPath/artisan");

// The ".env" only holds the defaults from the ".env.example", so it still needs filling in
assert_string_contains($output, 'Fill in the ".env" file');
