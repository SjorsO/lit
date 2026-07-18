<?php

require __DIR__.'/../test-helpers.php';

// Test init in a non-zero downtime Laravel project with SQLite files

$worldPath = world_path();
$projectPath = "$worldPath/case/my-app";

mkdir("$projectPath/database", 0777, true);
touch("$projectPath/artisan");
touch("$projectPath/database/database.sqlite");

chdir($projectPath);

[$statusCode, $output] = lit('init', 'https://github.com/SjorsO/lit.git', '.');

assert_same(0, $statusCode);

assert_same(<<<EXPECTED
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
- Run "lit checkout <branch>" to deploy a different branch

After you have deployed with Lit:
- Update your cron and queue workers to point at "/current/artisan" instead of "/artisan"
- Update your nginx to point at "/current/public/index.php" instead of "/public/index.php"

(Optional) Delete the original Laravel project files, keeping only:
- Directories: current/, hooks/, logs/, releases/, storage/
- Files: .env, git-repository-url, git-branch, git-commit

Warning:
The SQLite files in your "database/" directory must be moved.
Move them to the root of your project and set this in your ".env":
DB_DATABASE="$worldPath/case/my-app/database.sqlite"
EXPECTED, $output);
