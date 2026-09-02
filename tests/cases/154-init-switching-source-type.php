<?php

require __DIR__.'/../test-helpers.php';

$projectPath = world_path().'/case/my-app';

// First bundle init
[$statusCode, $output] = lit('init', 'https://example.com/releases/my-app.tar.gz');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Bundle URL set to "https://example.com/releases/my-app.tar.gz"

Finished initializing "my-app"

Next steps:
- cd "my-app"
- Fill in the ".env" file
- Review these newly created hooks:
  - "hooks/before-release.sh"
  - "hooks/after-release.sh"
  - "hooks/on-failure.sh"

After that, run "lit deploy" to download and deploy the bundle
EXPECTED, $output);

// Fill in .env so it doesn't show in next steps
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// Second bundle init (different URL, same project)
[$statusCode, $output] = lit('init', 'https://example.com/releases/my-app-v2.tar.gz', '.');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Changing from bundle URL: https://example.com/releases/my-app.tar.gz
Bundle URL set to "https://example.com/releases/my-app-v2.tar.gz"

Finished initializing "my-app"

Run "lit deploy" to download and deploy the bundle
EXPECTED, $output);

// Re-init never touches an existing ".env", the APP_KEY is kept
assert_file_content("$projectPath/.env", 'APP_KEY=test');

// Switch to git
[$statusCode, $output] = lit('init', 'https://github.com/SjorsO/lit.git', '.');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Reading "https://github.com/SjorsO/lit.git"... Done!

Changing from bundle URL: https://example.com/releases/my-app-v2.tar.gz
Current branch set to "main"

Finished initializing "my-app"

Next steps:
- Review these hooks:
  - "hooks/before-release.sh"
  - "hooks/after-release.sh"
  - "hooks/on-failure.sh"

After that, either:
- Run "lit deploy" to deploy the current branch (main)
- Run "lit checkout <branch|tag|commit>" to deploy something else
EXPECTED, $output);

// The bundle keys were replaced with git keys
assert_file_content("$projectPath/lit.json", <<<'EXPECTED'
{
    "git_repository_url": "https://github.com/SjorsO/lit.git",
    "git_ref": "main",
    "git_ref_type": "branch",
    "git_commit_sha": "not deployed yet",
    "git_release_caching_enabled": false
}
EXPECTED);

// Another git init (same source type)
[$statusCode, $output] = lit('init', 'https://github.com/SjorsO/lit.git', '.');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Reading "https://github.com/SjorsO/lit.git"... Done!

Changing from git repository URL: https://github.com/SjorsO/lit.git
Current branch set to "main"

Finished initializing "my-app"

Run "lit deploy" to deploy the current branch (main)
Run "lit checkout <branch|tag|commit>" to deploy something else
EXPECTED, $output);

// Empty the branch to test the "no branch" fallback
set_lit_state_value($projectPath, 'git_ref', '');

// Switch back to bundle
[$statusCode, $output] = lit('init', 'https://example.com/final-bundle.tar', '.');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Changing from git URL: https://github.com/SjorsO/lit.git (branch: no branch)
Bundle URL set to "https://example.com/final-bundle.tar"

Finished initializing "my-app"

Next steps:
- Review these hooks:
  - "hooks/before-release.sh"
  - "hooks/after-release.sh"
  - "hooks/on-failure.sh"

After that, run "lit deploy" to download and deploy the bundle
EXPECTED, $output);

// The git keys were replaced with bundle keys
assert_file_content("$projectPath/lit.json", <<<'EXPECTED'
{
    "bundle_url": "https://example.com/final-bundle.tar",
    "bundle_hash": "not deployed yet"
}
EXPECTED);

// Delete a hook, then init again - should recreate it and show message
unlink("$projectPath/hooks/before-release.sh");

[$statusCode, $output] = lit('init', 'https://example.com/another-bundle.tar', '.');

assert_same(0, $statusCode);
assert_file_exists("$projectPath/hooks/before-release.sh");

// The ".env" survived every re-init above, including the git one
assert_file_content("$projectPath/.env", 'APP_KEY=test');

assert_same(<<<'EXPECTED'
Changing from bundle URL: https://example.com/final-bundle.tar
Bundle URL set to "https://example.com/another-bundle.tar"

Finished initializing "my-app"

Next steps:
- Review these newly created hooks:
  - "hooks/before-release.sh"

After that, run "lit deploy" to download and deploy the bundle
EXPECTED, $output);
