<?php

require __DIR__.'/../test-helpers.php';

// Lit v1 stored state in separate files. Running any command in a v1
// project must migrate that state into "lit.json" automatically.

$worldPath = world_path();

// A v1 git project, with release caching enabled
$gitProjectPath = "$worldPath/case/git-project";

mkdir("$gitProjectPath/storage", 0777, true);
mkdir("$gitProjectPath/hooks", 0777, true);
mkdir("$gitProjectPath/releases", 0777, true);

file_put_contents("$gitProjectPath/.env", "APP_KEY=test\n");
file_put_contents("$gitProjectPath/git-repository-url", "https://github.com/SjorsO/lit.git\n");
file_put_contents("$gitProjectPath/git-branch", "production\n");
file_put_contents("$gitProjectPath/git-commit", "0123456789abcdef0123456789abcdef01234567\n");
touch("$gitProjectPath/git-release-caching-enabled");

chdir($gitProjectPath);

[$statusCode, $output] = lit('disable-git-release-caching');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Migrated Lit v1 state files into "lit.json"
Release caching for git disabled

Review and update these hooks:
- "hooks/before-release.sh"
- "hooks/after-release.sh"
EXPECTED, $output);

// All v1 values survived the migration, and the disable command set caching to false
assert_file_content("$gitProjectPath/lit.json", <<<'EXPECTED'
{
    "git_repository_url": "https://github.com/SjorsO/lit.git",
    "git_ref": "production",
    "git_ref_type": "branch",
    "git_commit_sha": "0123456789abcdef0123456789abcdef01234567",
    "git_release_caching_enabled": false
}
EXPECTED);

// The v1 state files are gone
assert_file_missing("$gitProjectPath/git-repository-url");
assert_file_missing("$gitProjectPath/git-branch");
assert_file_missing("$gitProjectPath/git-commit");
assert_file_missing("$gitProjectPath/git-release-caching-enabled");

// The next command must not migrate again
[$statusCode, $output] = lit('enable-git-release-caching');

assert_same(0, $statusCode);
assert_string_not_contains($output, 'Migrated');
assert_lit_state_value($gitProjectPath, 'git_release_caching_enabled', true);

// A v1 git project missing the optional files
$bareProjectPath = "$worldPath/case/bare-git-project";

mkdir("$bareProjectPath/storage", 0777, true);

file_put_contents("$bareProjectPath/git-repository-url", "https://github.com/SjorsO/lit.git\n");
file_put_contents("$bareProjectPath/git-branch", "main\n");

chdir($bareProjectPath);

[$statusCode, $output] = lit('disable-git-release-caching');

// Caching was not enabled in v1, so disabling fails
assert_same(1, $statusCode);

assert_same(<<<'EXPECTED'
Migrated Lit v1 state files into "lit.json"
Release caching for git is already disabled
EXPECTED, $output);

// A missing "git-commit" file falls back to the default
assert_file_content("$bareProjectPath/lit.json", <<<'EXPECTED'
{
    "git_repository_url": "https://github.com/SjorsO/lit.git",
    "git_ref": "main",
    "git_ref_type": "branch",
    "git_commit_sha": "not deployed yet",
    "git_release_caching_enabled": false
}
EXPECTED);
assert_file_missing("$bareProjectPath/git-repository-url");
assert_file_missing("$bareProjectPath/git-branch");

// A v1 bundle project
$bundleProjectPath = "$worldPath/case/bundle-project";

mkdir("$bundleProjectPath/storage", 0777, true);

file_put_contents("$bundleProjectPath/bundle-url", "https://example.com/releases/app.tar.gz\n");
file_put_contents("$bundleProjectPath/bundle-hash", "95632a16d315752fc2c8e5b298546b389094a9d2\n");

chdir($bundleProjectPath);

[$statusCode, $output] = lit('enable-git-release-caching');

// The command fails for bundles, but the migration still ran
assert_same(1, $statusCode);

assert_same(<<<'EXPECTED'
Migrated Lit v1 state files into "lit.json"
Release caching is only available when deploying from git
EXPECTED, $output);

assert_file_content("$bundleProjectPath/lit.json", <<<'EXPECTED'
{
    "bundle_url": "https://example.com/releases/app.tar.gz",
    "bundle_hash": "95632a16d315752fc2c8e5b298546b389094a9d2"
}
EXPECTED);
assert_file_missing("$bundleProjectPath/bundle-url");
assert_file_missing("$bundleProjectPath/bundle-hash");
