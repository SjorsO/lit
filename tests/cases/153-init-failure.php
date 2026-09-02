<?php

require __DIR__.'/../test-helpers.php';

// Test lit init when git call fails (invalid repository)

$worldPath = world_path();

// GIT_TERMINAL_PROMPT=0 prevents git from asking for credentials
[$statusCode, $output] = lit_with_environment(['GIT_TERMINAL_PROMPT' => '0'], 'init', 'https://github.com/SjorsO/this-repo-does-not-exist-12345.git');

// Should fail because repository doesn't exist
assert_same(128, $statusCode);

// Directory should not be created
assert_file_missing("$worldPath/case/this-repo-does-not-exist-12345/storage");

// Bundle init with non-existent URL should succeed (validation happens at deploy time)
[$statusCode, $output] = lit('init', 'https://example.com/this-does-not-exist.tar.gz');

assert_same(0, $statusCode);
assert_lit_state_value("$worldPath/case/this-does-not-exist", 'bundle_url', 'https://example.com/this-does-not-exist.tar.gz');

// Invalid custom project names should be rejected
[$statusCode, $output] = lit('init', 'https://example.com/app.tar.gz', 'path/traversal');

assert_same(1, $statusCode);
assert_same('Invalid project name "path/traversal"', $output);

[$statusCode, $output] = lit('init', 'https://example.com/app.tar.gz', '..');

assert_same(1, $statusCode);
assert_same('Invalid project name ".."', $output);

// Valid custom project names (spaces and special chars are allowed)
[$statusCode, $output] = lit('init', 'https://example.com/app.tar.gz', 'my-valid_project.name123');

assert_same(0, $statusCode);
assert_file_exists("$worldPath/case/my-valid_project.name123/lit.json");

[$statusCode, $output] = lit('init', 'https://example.com/app.tar.gz', 'name with spaces');

assert_same(0, $statusCode);
assert_file_exists("$worldPath/case/name with spaces/lit.json");

[$statusCode, $output] = lit('init', 'https://example.com/app.tar.gz', 'special@chars!');

assert_same(0, $statusCode);
assert_file_exists("$worldPath/case/special@chars!/lit.json");

// Too many arguments should fail
[$statusCode, $output] = lit('init', 'https://example.com/app.tar.gz', 'project', 'extra-arg');

assert_same(1, $statusCode);

assert_same(<<<'EXPECTED'
usage: lit init <url> [project-name]

Examples:
  lit init https://github.com/user/repo.git
  lit init https://github.com/user/repo.git my-project
  lit init https://example.com/releases/app.tar.gz
EXPECTED, $output);
