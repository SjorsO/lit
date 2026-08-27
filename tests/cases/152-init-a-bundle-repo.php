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

// Assert lit.json has correct content
assert_lit_state_value($projectPath, 'bundle_url', 'https://example.com/releases/my-app.tar.gz');
assert_lit_state_value($projectPath, 'bundle_hash', 'not deployed yet');

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

// Assert git keys don't exist
assert_lit_state_missing($projectPath, 'git_repository_url');
assert_lit_state_missing($projectPath, 'git_branch');
assert_lit_state_missing($projectPath, 'git_commit');
assert_lit_state_missing($projectPath, 'git_release_caching_enabled');
