<?php

require __DIR__.'/../test-helpers.php';

// Test the enable-git-release-caching and disable-git-release-caching commands

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

chdir($projectPath);

// Verify caching is disabled by default
assert_file_missing("$projectPath/git-release-caching-enabled");
assert_file_missing("$projectPath/hooks/before-caching.sh");

// 1. Enable caching (first time) - should succeed and create hook
[$statusCode, $output] = lit('enable-git-release-caching');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Release caching for git enabled

Created new hook "lit/hooks/before-caching.sh"

Review and update these hooks:
- "hooks/before-caching.sh"
- "hooks/before-release.sh"
- "hooks/after-release.sh"
EXPECTED, $output);
assert_file_exists("$projectPath/git-release-caching-enabled");
assert_file_exists("$projectPath/hooks/before-caching.sh");

// 2. Enable caching (again) - should fail
[$statusCode, $output] = lit('enable-git-release-caching');

assert_same(1, $statusCode);
assert_same('Release caching for git is already enabled', $output);

// 3. Disable caching - should succeed
[$statusCode, $output] = lit('disable-git-release-caching');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Release caching for git disabled

Review and update these hooks:
- "hooks/before-release.sh"
- "hooks/after-release.sh"

This hook will not be used anymore: "lit/hooks/before-caching.sh"
EXPECTED, $output);
assert_file_missing("$projectPath/git-release-caching-enabled");
assert_file_exists("$projectPath/hooks/before-caching.sh");

// 4. Disable caching (again) - should fail
[$statusCode, $output] = lit('disable-git-release-caching');

assert_same(1, $statusCode);
assert_same('Release caching for git is already disabled', $output);

// 5. Enable caching (hook still exists) - should succeed but not create hook
[$statusCode, $output] = lit('enable-git-release-caching');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Release caching for git enabled

Review and update these hooks:
- "hooks/before-caching.sh"
- "hooks/before-release.sh"
- "hooks/after-release.sh"
EXPECTED, $output);
assert_file_exists("$projectPath/git-release-caching-enabled");

// 6. Delete hook manually and disable caching - should succeed
unlink("$projectPath/hooks/before-caching.sh");

[$statusCode, $output] = lit('disable-git-release-caching');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
Release caching for git disabled

Review and update these hooks:
- "hooks/before-release.sh"
- "hooks/after-release.sh"
EXPECTED, $output);
assert_file_missing("$projectPath/git-release-caching-enabled");
assert_file_missing("$projectPath/hooks/before-caching.sh");
