<?php

require __DIR__.'/../test-helpers.php';

// Test that Lit uses the Deployer shared directory when it exists

$projectPath = world_path().'/case/deployer-project';

// Set up a Deployer-style directory structure
foreach (['app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $storageSubdirectory) {
    mkdir("$projectPath/shared/storage/$storageSubdirectory", 0777, true);
}

file_put_contents("$projectPath/shared/.env", "APP_KEY=from-shared\n");

// Set up Lit configuration manually (simulating a migration from Deployer)
file_put_contents("$projectPath/git-repository-url", "https://github.com/SjorsO/lit.git\n");
file_put_contents("$projectPath/git-branch", "main\n");
file_put_contents("$projectPath/git-commit", "not deployed yet\n");

mkdir("$projectPath/hooks");

neutralize_hooks($projectPath);

mkdir("$projectPath/releases");

chdir($projectPath);

[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

// Verify the release was created
assert_directory_exists("$projectPath/releases/1");

// Verify the .env symlink points to shared/.env
assert_symlink("$projectPath/releases/1/.env");
assert_string_contains(readlink("$projectPath/releases/1/.env"), 'shared/.env');

// Verify the storage symlink points to shared/storage
assert_symlink("$projectPath/releases/1/storage");
assert_string_contains(readlink("$projectPath/releases/1/storage"), 'shared/storage');

// Verify the .env content is from the shared directory
assert_string_contains(file_get_contents("$projectPath/releases/1/.env"), 'from-shared');
