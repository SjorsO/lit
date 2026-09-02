<?php

require __DIR__.'/../test-helpers.php';

// Test that hooks receive correct $1 (project_base_path), $2 (new_release_directory), $3 (lit_base_path),
// and that the before-release and after-release hooks also receive $4 (previous_release_directory)

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git', 'the-project');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/the-project';

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

// Create hooks that write their arguments to files
file_put_contents("$projectPath/hooks/before-release.sh", <<<'HOOK'
echo "$1" > "$1/before-release-arg1"
echo "$2" > "$1/before-release-arg2"
echo "$3" > "$1/before-release-arg3"
echo "$4" > "$1/before-release-arg4"
HOOK."\n");

file_put_contents("$projectPath/hooks/after-release.sh", <<<'HOOK'
echo "$1" > "$1/after-release-arg1"
echo "$2" > "$1/after-release-arg2"
echo "$3" > "$1/after-release-arg3"
echo "$4" > "$1/after-release-arg4"
HOOK."\n");

file_put_contents("$projectPath/hooks/on-failure.sh", <<<'HOOK'
echo "$1" > "$1/on-failure-arg1"
echo "$2" > "$1/on-failure-arg2"
HOOK."\n");

chdir($projectPath);

// Deploy
[$statusCode] = lit('deploy');

assert_same(0, $statusCode);

// Verify before-release hook arguments
assert_file_content("$projectPath/before-release-arg1", $projectPath);
assert_string_contains(file_get_contents("$projectPath/before-release-arg2"), 'releases/1');
assert_file_exists(rtrim(file_get_contents("$projectPath/before-release-arg3"), "\n").'/lit.php');

// $4 is the previous release directory, which is empty on the first deploy
assert_file_content("$projectPath/before-release-arg4", '');

// Verify after-release hook arguments
assert_file_content("$projectPath/after-release-arg1", $projectPath);
assert_string_contains(file_get_contents("$projectPath/after-release-arg2"), 'releases/1');
assert_file_exists(rtrim(file_get_contents("$projectPath/after-release-arg3"), "\n").'/lit.php');

// $4 is the previous release directory, which is empty on the first deploy
assert_file_content("$projectPath/after-release-arg4", '');

// on-failure hook should not have been called (deploy succeeded)
assert_file_missing("$projectPath/on-failure-arg1");

// Now deploy a second time. The after-release hook records its $4 and then fails, which also lets
// us test the on-failure hook.
file_put_contents("$projectPath/hooks/on-failure.sh", 'echo "$1" > "$1/on-failure-arg1"; echo "$2" > "$1/on-failure-arg2"'."\n");
file_put_contents("$projectPath/hooks/after-release.sh", 'echo "$4" > "$1/after-release-arg4"; exit 1'."\n");

[$statusCode] = lit('deploy', '--force');

assert_same(1, $statusCode);

// On the second deploy, both hooks receive the previous release directory ("releases/1") as $4, and
// it still exists when the hooks run because pruning of old releases only happens after them. The
// before-release hook still records its arguments (it was not overwritten above).
$beforeArg4 = rtrim(file_get_contents("$projectPath/before-release-arg4"), "\n");

assert_string_contains($beforeArg4, 'releases/1');
assert_directory_exists($beforeArg4);

$afterArg4 = rtrim(file_get_contents("$projectPath/after-release-arg4"), "\n");

assert_string_contains($afterArg4, 'releases/1');
assert_directory_exists($afterArg4);

// Verify on-failure hook arguments
// $1 should be project_base_path
assert_file_content("$projectPath/on-failure-arg1", $projectPath);

// $2 should be "true" (was released)
assert_file_content("$projectPath/on-failure-arg2", 'true');
