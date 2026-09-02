<?php

require __DIR__.'/../test-helpers.php';

// Test caching with symlinked before-caching.sh hook shared between two projects

$worldPath = world_path();

// Init two projects
chdir("$worldPath/case");

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git', 'project1');

assert_same(0, $statusCode);

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git', 'project2');

assert_same(0, $statusCode);

$project1Path = "$worldPath/case/project1";
$project2Path = "$worldPath/case/project2";

// Setup both projects
foreach ([$project1Path, $project2Path] as $projectPath) {
    neutralize_hooks($projectPath);
    file_put_contents("$projectPath/.env", "APP_KEY=test\n");

    chdir($projectPath);

    [$statusCode] = lit('enable-git-release-caching');

    assert_same(0, $statusCode);

    unlink("$projectPath/hooks/before-caching.sh");
}

// Create a shared hook outside both projects that verifies all 3 arguments
mkdir("$worldPath/case/shared-hooks");

file_put_contents("$worldPath/case/shared-hooks/before-caching.sh", <<<'HOOK'
touch "$1/shared-hook-ran"
echo "$2" > "$1/before-caching-arg2"
echo "$3" > "$1/before-caching-arg3"
HOOK."\n");

// Symlink the shared hook to both projects
symlink("$worldPath/case/shared-hooks/before-caching.sh", "$project1Path/hooks/before-caching.sh");
symlink("$worldPath/case/shared-hooks/before-caching.sh", "$project2Path/hooks/before-caching.sh");

// Deploy project1
chdir($project1Path);

[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);
assert_file_exists("$project1Path/releases/1/shared-hook-ran");

// Verify before-caching hook received correct $2 (project_base_path) and $3 (lit_base_path)
assert_file_content("$project1Path/releases/1/before-caching-arg2", $project1Path);
assert_file_exists(rtrim(file_get_contents("$project1Path/releases/1/before-caching-arg3"), "\n").'/lit.php');

// Deploy project2 - should reuse cache from project1
chdir($project2Path);

[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Reusing deployment from cache
Creating "$project2Path/releases/1" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$project2Path/releases/1"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_file_exists("$project2Path/releases/1/shared-hook-ran");

// Changing the shared hook should invalidate cache
file_put_contents("$worldPath/case/shared-hooks/before-caching.sh", 'touch "$1/shared-hook-v2"'."\n");

// Without --force, deploy is skipped even if hook changed
chdir($project1Path);

[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)
EXPECTED, $output);
assert_file_missing("$project1Path/releases/2");

// Project1 should detect hook changed and rebuild
[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Cached release found but hook changed, rebuilding...
Cloning repository...
Running "project1/hooks/before-caching.sh"...
Caching release...
Creating "$project1Path/releases/2" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$project1Path/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_file_exists("$project1Path/releases/2/shared-hook-v2");

// Project2 reuses the cache that project1 just rebuilt
chdir($project2Path);

[$statusCode, $output] = lit('deploy', '--force');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "main" of "https://github.com/SjorsO/lit.git"...
Latest commit of "main" is already deployed (COMMIT)
Using "--force", redeploying...
Reusing deployment from cache
Creating "$project2Path/releases/2" for the new release...
Extracting release...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$project2Path/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);
assert_file_exists("$project2Path/releases/2/shared-hook-v2");
