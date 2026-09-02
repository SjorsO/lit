<?php

require __DIR__.'/../test-helpers.php';

// Test "lit init" with a git repository that does not exist. A private repository you have
// no access to hits this same path: GitHub does not leak whether a repository exists, so it
// returns the same error for both.

$worldPath = world_path();

$missingRepoUrl = 'https://github.com/SjorsO/this-repo-does-not-exist-12345.git';

// GIT_TERMINAL_PROMPT=0 stops git from asking for credentials and hanging the test
[$statusCode, $output] = lit_with_environment(['GIT_TERMINAL_PROMPT' => '0'], 'init', $missingRepoUrl);

// git exits with 128, Lit passes that status code through
assert_same(128, $statusCode);

// The error from git is shown, and "Done!" is never reached
assert_string_contains($output, "Reading \"$missingRepoUrl\"... ");
assert_string_contains($output, 'fatal:');
assert_string_not_contains($output, 'Done!');
assert_string_not_contains($output, 'Finished initializing');
assert_string_not_contains($output, 'Next steps:');

// A GitHub https url can't use a deploy key, so Lit shows the ssh url instead
assert_string_contains($output, "Tip: with the SSH URL, Lit can set up a deploy key for you:\n  lit init git@github.com:SjorsO/this-repo-does-not-exist-12345.git");
assert_string_not_contains($output, 'Generate a deploy key');

// Nothing is created, not even the project directory
assert_file_missing("$worldPath/case/this-repo-does-not-exist-12345");

// A failing init must leave an existing Lit project untouched, a typo in the URL
// should never wipe out a working project. This first init creates that project.
[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git', 'existing-project');

assert_same(0, $statusCode);

$projectPath = "$worldPath/case/existing-project";

file_put_contents("$projectPath/.env", "APP_NAME=keep-me\n");

chdir($projectPath);

[$statusCode, $output] = lit_with_environment(['GIT_TERMINAL_PROMPT' => '0'], 'init', $missingRepoUrl, '.');

assert_same(128, $statusCode);
assert_string_not_contains($output, 'Changing from git repository URL');

// The tip keeps the project name argument
assert_string_contains($output, "\n  lit init git@github.com:SjorsO/this-repo-does-not-exist-12345.git .");

// The existing configuration survived
assert_file_content("$projectPath/lit.json", <<<'EXPECTED'
{
    "git_repository_url": "https://github.com/SjorsO/lit.git",
    "git_ref": "main",
    "git_ref_type": "branch",
    "git_commit_sha": "not deployed yet",
    "git_release_caching_enabled": false
}
EXPECTED);
assert_file_content("$projectPath/.env", 'APP_NAME=keep-me');

assert_directory_exists("$projectPath/storage");
assert_directory_exists("$projectPath/hooks");
assert_directory_exists("$projectPath/releases");

// No half finished ".env.example" clone is left behind
assert_file_missing("$projectPath/env-example-clone");
