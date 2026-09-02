<?php

require __DIR__.'/../test-helpers.php';

// Deploy when the current branch or tag no longer exists on the remote.
// The deploy must stop right away, before creating a release directory.

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// Manually set the branch to one that doesn't exist
set_lit_state_value($projectPath, 'git_ref', 'deleted-branch-that-does-not-exist');

[$statusCode, $output] = lit('deploy');

assert_same(1, $statusCode);

$output = normalize_output($output);

assert_same(<<<'EXPECTED'
Reading branch "deleted-branch-that-does-not-exist" of "https://github.com/SjorsO/lit.git"...
Branch "deleted-branch-that-does-not-exist" does not exist on the remote
Finished with errors (in X seconds)
EXPECTED, $output);

// No release directory was created
assert_file_missing("$projectPath/releases/1");

// The same for a tag that doesn't exist
set_lit_state_value($projectPath, 'git_ref', 'v99-does-not-exist');
set_lit_state_value($projectPath, 'git_ref_type', 'tag');

[$statusCode, $output] = lit('deploy');

assert_same(1, $statusCode);

$output = normalize_output($output);

assert_same(<<<'EXPECTED'
Reading tag "v99-does-not-exist" of "https://github.com/SjorsO/lit.git"...
Tag "v99-does-not-exist" does not exist on the remote
Finished with errors (in X seconds)
EXPECTED, $output);

assert_file_missing("$projectPath/releases/1");

// The log entry says why the deploy failed
$logContent = file_get_contents("$projectPath/logs/lit.log");

assert_string_contains($logContent, '→ failed (the branch does not exist on the remote) (in ');
assert_string_contains($logContent, '→ failed (the tag does not exist on the remote) (in ');
