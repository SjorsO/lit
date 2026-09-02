<?php

require __DIR__.'/../test-helpers.php';

// Test that lit help works outside a lit directory

$worldPath = world_path();

// We're in $worldPath/case which is not a lit directory
[$statusCode, $output] = lit('help');

// Help should work even outside a lit directory
assert_same(0, $statusCode);
assert_output_is_help_text($output);

// Also test that init works outside lit directory (it should)
[$statusCode, $output] = lit('init');

// Should show usage (exit 1) but not "This is not a Lit directory"
assert_same(1, $statusCode);

assert_same(<<<'EXPECTED'
usage: lit init <url> [project-name]

Examples:
  lit init https://github.com/user/repo.git
  lit init https://github.com/user/repo.git my-project
  lit init https://example.com/releases/app.tar.gz
EXPECTED, $output);

// Test that lit help inside a lit directory doesn't log
[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

chdir("$worldPath/case/lit");

[$statusCode, $output] = lit('help');

assert_same(0, $statusCode);
assert_output_is_help_text($output);

// Help should not be logged
$outputLogContent = is_file("$worldPath/case/lit/logs/lit-output.log") ? file_get_contents("$worldPath/case/lit/logs/lit-output.log") : '';

assert_string_not_contains($outputLogContent, 'lit help');

// Test that lit deploy fails inside the lit installation directory
chdir("$worldPath/lit");

[$statusCode, $output] = lit('deploy');

assert_same(1, $statusCode);
assert_same('This is not a Lit directory', $output);

chdir($worldPath);

[$statusCode, $output] = lit('deploy');

assert_same(1, $statusCode);
assert_same('This is not a Lit directory', $output);
