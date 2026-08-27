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
assert_lit_state_value($projectPath, 'git_repository_url', 'https://github.com/SjorsO/lit.git');
assert_lit_state_value($projectPath, 'git_branch', 'main');
assert_lit_state_value($projectPath, 'git_commit', 'not deployed yet');
assert_lit_state_value($projectPath, 'git_release_caching_enabled', false);

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

// Assert bundle keys don't exist
assert_lit_state_missing($projectPath, 'bundle_url');
assert_lit_state_missing($projectPath, 'bundle_hash');

// Init a repo that has a ".env.example", the ".env" should be created from it
[$statusCode, $output] = lit('init', 'https://github.com/SjorsO/for-Lit-unit-tests-01.git');

assert_same(0, $statusCode);
assert_string_contains($output, 'Created ".env" from the ".env.example" in the repository');
assert_string_contains($output, 'Application key (APP_KEY) set successfully.');

$envExampleProjectPath = "$worldPath/case/for-Lit-unit-tests-01";

$envContents = file_get_contents("$envExampleProjectPath/.env");

// The rest of the ".env.example" is copied over as-is
assert_string_contains($envContents, "APP_NAME=Laravel\n");
assert_string_contains($envContents, "APP_URL=http://localhost:8000\n");
assert_string_contains($envContents, "DB_CONNECTION=sqlite\n");

// The empty "APP_KEY=" is filled in with a generated key (base64 of 32 random bytes)
assert_string_not_contains($envContents, "APP_KEY=\n");
assert_matches('/^APP_KEY=base64:[A-Za-z0-9+\/]{43}=$/m', $envContents);

// The temporary clone directory is cleaned up
assert_file_missing("$envExampleProjectPath/env-example-clone");

// None of the other repository files are checked out
assert_file_missing("$envExampleProjectPath/.env.example");
assert_file_missing("$envExampleProjectPath/composer.json");
assert_file_missing("$envExampleProjectPath/artisan");

// The ".env" only holds the defaults from the ".env.example", so it still needs filling in
assert_string_contains($output, 'Fill in the ".env" file');
