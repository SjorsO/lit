<?php

require __DIR__.'/../test-helpers.php';

// Test init command edge cases

$worldPath = world_path();

$expectedUsage = <<<'EXPECTED'
usage: lit init <url> [project-name]

Examples:
  lit init https://github.com/user/repo.git
  lit init https://github.com/user/repo.git my-project
  lit init https://example.com/releases/app.tar.gz
EXPECTED;

// No URL provided should show usage
[$statusCode, $output] = lit('init');

assert_same(1, $statusCode);
assert_same($expectedUsage, $output);

// Too many arguments should show usage
[$statusCode, $output] = lit('init', 'https://github.com/user/repo.git', 'my-project', 'extra-arg');

assert_same(1, $statusCode);
assert_same($expectedUsage, $output);

// Invalid project name ".." should be rejected
[$statusCode, $output] = lit('init', 'https://example.com/bundle.tar.gz', '..');

assert_same(1, $statusCode);
assert_same('Invalid project name ".."', $output);

// Invalid project name with slash should be rejected
[$statusCode, $output] = lit('init', 'https://example.com/bundle.tar.gz', 'foo/bar');

assert_same(1, $statusCode);
assert_same('Invalid project name "foo/bar"', $output);

// Project name with space is valid
[$statusCode, $output] = lit('init', 'https://example.com/bundle.tar.gz', 'my project');

assert_same(0, $statusCode);
assert_directory_exists("$worldPath/case/my project");

// Project name with @ is valid
[$statusCode, $output] = lit('init', 'https://example.com/bundle.tar.gz', 'my@project');

assert_same(0, $statusCode);
assert_directory_exists("$worldPath/case/my@project");

// Directory already exists and is not empty should fail
mkdir("$worldPath/case/existing-project");
file_put_contents("$worldPath/case/existing-project/file.txt", "some content\n");

[$statusCode, $output] = lit('init', 'https://github.com/SjorsO/existing-project.git');

assert_same(1, $statusCode);
assert_same('Directory "existing-project" already exists and is not a Laravel project', $output);

// Directory already exists but is empty should succeed
mkdir("$worldPath/case/empty-project");

[$statusCode, $output] = lit('init', 'https://example.com/releases/empty-project.tar.gz');

assert_same(0, $statusCode);
assert_lit_state_value("$worldPath/case/empty-project", 'bundle_url', 'https://example.com/releases/empty-project.tar.gz');

// Test .tar.zst extension is recognized as bundle and stripped properly
chdir("$worldPath/case");

[$statusCode, $output] = lit('init', 'https://example.com/releases/another-app.tar.zst');

assert_same(0, $statusCode);
assert_directory_exists("$worldPath/case/another-app");
assert_lit_state_value("$worldPath/case/another-app", 'bundle_url', 'https://example.com/releases/another-app.tar.zst');

// Test that project name is extracted from before the last .tar
[$statusCode, $output] = lit('init', 'https://example.com/gus.tarballs.tar');

assert_same(0, $statusCode);
assert_directory_exists("$worldPath/case/gus.tarballs");
assert_lit_state_value("$worldPath/case/gus.tarballs", 'bundle_url', 'https://example.com/gus.tarballs.tar');

// Test that "." as project name fails if directory is not empty and not a zero downtime project
mkdir("$worldPath/case/not-zero downtime");
file_put_contents("$worldPath/case/not-zero downtime/file.txt", "some content\n");

chdir("$worldPath/case/not-zero downtime");

[$statusCode, $output] = lit('init', 'https://example.com/bundle.tar.gz', '.');

assert_same(1, $statusCode);
assert_same('Directory "not-zero downtime" already exists and is not a Laravel project', $output);
