<?php

require __DIR__.'/../test-helpers.php';

// Test that the help command, unknown commands, and no command show help

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

chdir(world_path().'/case/lit');

[$statusCode, $output] = lit('help');

assert_same(0, $statusCode);
assert_output_is_help_text($output);

// Unknown command should show help
[$statusCode, $output] = lit('unknowncommand');

assert_same(1, $statusCode);
assert_output_is_help_text($output);

// No command at all should also show help
[$statusCode, $output] = lit();

assert_same(1, $statusCode);
assert_output_is_help_text($output);
