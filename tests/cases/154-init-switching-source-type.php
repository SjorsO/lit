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
- Run "lit checkout <branch>" to deploy a different branch
EXPECTED, $output);

// Assert bundle files were deleted
assert_file_missing("$projectPath/bundle-url");
assert_file_missing("$projectPath/bundle-hash");

// Another git init (same source type)
[$statusCode, $output] = lit('init', 'https://github.com/SjorsO/lit.git', '.');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Reading "https://github.com/SjorsO/lit.git"... Done!

Changing from git repository URL: https://github.com/SjorsO/lit.git
Current branch set to "main"

Finished initializing "my-app"

Run "lit deploy" to deploy the current branch (main)
Run "lit checkout <branch>" to deploy a different branch
EXPECTED, $output);

// Delete git-branch file to test "no branch" fallback
unlink("$projectPath/git-branch");

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

// Assert git files were deleted
assert_file_missing("$projectPath/git-repository-url");
assert_file_missing("$projectPath/git-branch");
assert_file_missing("$projectPath/git-commit");

// Delete a hook, then init again - should recreate it and show message
unlink("$projectPath/hooks/before-release.sh");

[$statusCode, $output] = lit('init', 'https://example.com/another-bundle.tar', '.');

assert_same(0, $statusCode);
assert_file_exists("$projectPath/hooks/before-release.sh");

assert_same(<<<'EXPECTED'
Changing from bundle URL: https://example.com/final-bundle.tar
Bundle URL set to "https://example.com/another-bundle.tar"

Finished initializing "my-app"

Next steps:
- Review these newly created hooks:
  - "hooks/before-release.sh"

After that, run "lit deploy" to download and deploy the bundle
EXPECTED, $output);
