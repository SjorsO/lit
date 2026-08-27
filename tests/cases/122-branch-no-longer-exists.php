<?php

require __DIR__.'/../test-helpers.php';

// Test deploy when current branch no longer exists on remote

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// Manually set the branch to one that doesn't exist
set_lit_state_value($projectPath, 'git_ref', 'deleted-branch-that-does-not-exist');

[$statusCode, $output] = lit('deploy');

// Should fail because branch doesn't exist
assert_same(128, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
Reading branch "deleted-branch-that-does-not-exist" of "https://github.com/SjorsO/lit.git"...
Creating "$projectPath/releases/1" for the new release...
Cloning repository... fatal: Remote branch deleted-branch-that-does-not-exist not found in upstream origin
Deleting new but unreleased release directory "$projectPath/releases/1"
Finished with errors (in X seconds)
EXPECTED, $output);

// No release should be created
assert_file_missing("$projectPath/releases/1");
