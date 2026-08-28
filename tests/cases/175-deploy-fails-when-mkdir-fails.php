<?php

require __DIR__.'/../test-helpers.php';

// When the release directory can not be created, the deploy must abort right there.
// Continuing after a failed mkdir could extract files into the wrong directory.

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

neutralize_hooks($projectPath);

chdir($projectPath);

// A read-only releases directory makes the mkdir of the release fail
chmod("$projectPath/releases", 0555);

[$deployStatusCode, $output] = lit('deploy');

// Restore permissions right away, the world must stay deletable
chmod("$projectPath/releases", 0755);

assert_same(1, $deployStatusCode);

assert_string_contains($output, "Failed to create \"$projectPath/releases/1\"");
assert_string_contains($output, 'Finished with errors');

// Nothing was cloned, extracted, or released
assert_same([], glob("$projectPath/releases/*"));
assert_file_missing("$projectPath/current");
assert_lit_state_value($projectPath, 'git_commit_sha', 'not deployed yet');

// The lock was released, the log placeholder was finished
assert_file_missing("$projectPath/lit-is-currently-running");
assert_string_not_contains(file_get_contents("$projectPath/logs/lit.log"), '(pending:');
