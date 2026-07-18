<?php

require __DIR__.'/../test-helpers.php';

[$statusCode, $output] = lit('init', 'https://example.com/releases/my-app.tar.gz');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/my-app";

// Assert directories exist
assert_directory_exists("$projectPath/storage");
assert_directory_exists("$projectPath/hooks");
assert_directory_exists("$projectPath/releases");

// Assert lit config files have correct content
assert_file_content("$projectPath/bundle-url", 'https://example.com/releases/my-app.tar.gz');
assert_file_content("$projectPath/bundle-hash", 'not deployed yet');

// Assert hooks are copied from the bundle stubs
assert_files_match("$projectPath/hooks/before-release.sh", "$worldPath/lit/stubs/hooks-for-bundle/before-release.sh.stub");
assert_files_match("$projectPath/hooks/after-release.sh", "$worldPath/lit/stubs/hooks-for-bundle/after-release.sh.stub");
assert_files_match("$projectPath/hooks/on-failure.sh", "$worldPath/lit/stubs/on-failure.sh.stub");

// Assert .env file exists and is empty
assert_file_exists("$projectPath/.env");
assert_file_content("$projectPath/.env", '');

// Assert current symlink doesn't exist yet (created after first deployment)
assert_file_missing("$projectPath/current");

// Assert before-caching hook isn't created (caching is only for git)
assert_file_missing("$projectPath/hooks/before-caching.sh");

// Assert git files don't exist
assert_file_missing("$projectPath/git-repository-url");
assert_file_missing("$projectPath/git-branch");
assert_file_missing("$projectPath/git-commit");
