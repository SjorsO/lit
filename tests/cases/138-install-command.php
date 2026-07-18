<?php

require __DIR__.'/../test-helpers.php';

// Test the install flow (non-interactive mode via piped input).
// In v2 the installer runs automatically the first time any lit command is used.

$worldPath = world_path();

// Remove installation-id to trigger install on next lit command
unlink("$worldPath/lit/data/installation-id");

// Create a fake shell config file for alias testing
$fakeRcFile = "$worldPath/.bashrc";

file_put_contents($fakeRcFile, "# fake bashrc\n");

chdir($worldPath);

// Test 1: Install with "no" to the alias prompt
[$statusCode, $output] = lit_with_input('n', ['HOME' => $worldPath], 'help');

// Should succeed (the install runs instead of the command)
assert_same(0, $statusCode);
assert_string_contains($output, 'Welcome to Lit');

// installation-id should be created and contain a lowercase UUID
assert_file_exists("$worldPath/lit/data/installation-id");
assert_matches('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', rtrim(file_get_contents("$worldPath/lit/data/installation-id"), "\n"));

// Alias should NOT be added to bashrc
assert_string_not_contains(file_get_contents($fakeRcFile), 'lit.php');

// Test 2: Reset and test with "yes" to the alias prompt
unlink("$worldPath/lit/data/installation-id");

file_put_contents($fakeRcFile, "# fresh bashrc\n");

[$statusCode, $output] = lit_with_input('y', ['HOME' => $worldPath], 'help');

assert_same(0, $statusCode);

// Alias should be added to bashrc
assert_string_contains(file_get_contents($fakeRcFile), 'lit.php');

// Test 3: Running again with an existing installation-id should not run the installer
[$statusCode, $output] = lit_with_input('', ['HOME' => $worldPath], 'help');

assert_same(0, $statusCode);
assert_string_not_contains($output, 'Welcome to Lit');
assert_string_contains($output, 'usage: lit <command>');

// Test 4: Installing when the alias already exists should not ask again
unlink("$worldPath/lit/data/installation-id");

[$statusCode, $output] = lit_with_input('', ['HOME' => $worldPath], 'help');

assert_same(0, $statusCode);
assert_string_contains($output, 'You already have an alias for Lit.');
assert_file_exists("$worldPath/lit/data/installation-id");
