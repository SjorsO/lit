<?php

require __DIR__.'/../test-helpers.php';

// Test init in a non-zero downtime Laravel project (git)

$worldPath = world_path();
$projectPath = "$worldPath/case/my-app";

mkdir($projectPath);
touch("$projectPath/artisan");

chdir($projectPath);

[$statusCode, $output] = lit('init', 'https://github.com/SjorsO/lit.git', '.');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Reading "https://github.com/SjorsO/lit.git"... Done!

Current branch set to "main"

Finished initializing "my-app"

Next steps:
- Fill in the ".env" file
- Review these newly created hooks:
  - "hooks/before-release.sh"
  - "hooks/after-release.sh"
  - "hooks/on-failure.sh"

After that, either:
- Run "lit deploy" to deploy the current branch (main)
- Run "lit checkout <branch|tag|commit>" to deploy something else

After you have deployed with Lit:
- Update your cron and queue workers to point at "/current/artisan" instead of "/artisan"
- Update your nginx to point at "/current/public/index.php" instead of "/public/index.php"

(Optional) Delete the original Laravel project files, keeping only:
- Directories: current/, hooks/, logs/, releases/, storage/
- Files: .env, lit.json
EXPECTED, $output);

// Fill in .env so it doesn't show in next steps
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

// Init again - should now detect it's a zero downtime project
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

// Test init in a non-zero downtime Laravel project (bundle)
$project2Path = "$worldPath/case/my-bundle-app";

mkdir($project2Path);
touch("$project2Path/composer.json");
file_put_contents("$project2Path/.env", "APP_KEY=test\n");

chdir("$worldPath/case");

[$statusCode, $output] = lit('init', 'https://example.com/releases/my-bundle-app.tar.gz');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Bundle URL set to "https://example.com/releases/my-bundle-app.tar.gz"

Finished initializing "my-bundle-app"

Next steps:
- cd "my-bundle-app"
- Review these newly created hooks:
  - "hooks/before-release.sh"
  - "hooks/after-release.sh"
  - "hooks/on-failure.sh"

After that, run "lit deploy" to download and deploy the bundle

After you have deployed with Lit:
- Update your cron and queue workers to point at "/current/artisan" instead of "/artisan"
- Update your nginx to point at "/current/public/index.php" instead of "/public/index.php"

(Optional) Delete the original Laravel project files, keeping only:
- Directories: current/, hooks/, releases/, storage/
- Files: .env, lit.json
EXPECTED, $output);

// Init again - should now detect it's a zero downtime project
chdir($project2Path);

[$statusCode, $output] = lit('init', 'https://example.com/releases/app.tar.gz', '.');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Changing from bundle URL: https://example.com/releases/my-bundle-app.tar.gz
Bundle URL set to "https://example.com/releases/app.tar.gz"

Finished initializing "my-bundle-app"

Run "lit deploy" to download and deploy the bundle
EXPECTED, $output);
