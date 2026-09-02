<?php

require __DIR__.'/../test-helpers.php';

// Test the install flow with short paths, all lines fit inside the box

$worldPath = world_path();

// Remove installation-id to trigger install on next lit command
unlink("$worldPath/lit/data/installation-id");

// Create a fake shell config file for alias testing
$fakeRcFile = "$worldPath/.bashrc";

file_put_contents($fakeRcFile, "# fake bashrc\n");

chdir($worldPath);

// Test 1: Install with "no" to the alias prompt
[$statusCode, $output] = lit_with_input('n', ['SHELL' => '/bin/bash', 'HOME' => $worldPath], 'help');

// Should succeed (the install runs instead of the command)
assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
                           ┌──────────────────┐
╭──────────────────────────┤  Welcome to Lit  ├──────────────────────────╮
│                          └──────────────────┘                          │
│  Add an alias for Lit?                                                 │
│                                                                        │
│  File:                                                                 │
│    ~/.bashrc                                                           │
│                                                                        │
│  Alias:                                                                │
│    alias lit="php ~/lit/lit.php"                                       │
│                                                                        │
╰────────────────────────────────────────────────────────────────────────╯
  (●) Yes    ( ) No                                ←/→ + enter to select

╭────────────────────────────────────────────────────────────────────────╮
│  Not adding alias.                                                     │
│                                                                        │
│  Setup complete. Rerun your command to continue.                       │
╰────────────────────────────────────────────────────────────────────────╯
EXPECTED, $output);

// installation-id should be created and contain a timestamp and a lowercase UUID
assert_file_exists("$worldPath/lit/data/installation-id");
assert_matches('/^[0-9]{10}:[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/', rtrim(file_get_contents("$worldPath/lit/data/installation-id"), "\n"));

// Alias should NOT be added to bashrc
assert_string_not_contains(file_get_contents($fakeRcFile), 'lit.php');

// Test 2: Reset and test with "yes" to the alias prompt
unlink("$worldPath/lit/data/installation-id");

file_put_contents($fakeRcFile, "# fresh bashrc\n");

[$statusCode, $output] = lit_with_input('y', ['SHELL' => '/bin/bash', 'HOME' => $worldPath], 'help');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
                           ┌──────────────────┐
╭──────────────────────────┤  Welcome to Lit  ├──────────────────────────╮
│                          └──────────────────┘                          │
│  Add an alias for Lit?                                                 │
│                                                                        │
│  File:                                                                 │
│    ~/.bashrc                                                           │
│                                                                        │
│  Alias:                                                                │
│    alias lit="php ~/lit/lit.php"                                       │
│                                                                        │
╰────────────────────────────────────────────────────────────────────────╯
  (●) Yes    ( ) No                                ←/→ + enter to select

╭────────────────────────────────────────────────────────────────────────╮
│  ✓ Alias added. Restart your shell to start using it.                  │
│                                                                        │
│  Setup complete. Rerun your command to continue.                       │
╰────────────────────────────────────────────────────────────────────────╯
EXPECTED, $output);

// The real alias with the full path is added, with exactly 1 empty line above it
assert_same(<<<EXPECTED
# fresh bashrc

alias lit="php '$worldPath/lit/lit.php'"

EXPECTED, file_get_contents($fakeRcFile));

// Test 3: Running again with an existing installation-id should not run the installer
[$statusCode, $output] = lit_with_input('', ['SHELL' => '/bin/bash', 'HOME' => $worldPath], 'help');

assert_same(0, $statusCode);
assert_output_is_help_text($output);

// Test 4: If the alias already exists, install silently and run the command
unlink("$worldPath/lit/data/installation-id");

[$statusCode, $output] = lit_with_input('', ['SHELL' => '/bin/bash', 'HOME' => $worldPath], 'help');

assert_same(0, $statusCode);
assert_output_is_help_text($output);
assert_file_exists("$worldPath/lit/data/installation-id");

// Test 5: Q or escape instantly quits the install prompt
unlink("$worldPath/lit/data/installation-id");

file_put_contents($fakeRcFile, "# fresh bashrc\n");

[$statusCode] = lit_with_input('q', ['SHELL' => '/bin/bash', 'HOME' => $worldPath], 'help');

assert_same(130, $statusCode);
assert_file_missing("$worldPath/lit/data/installation-id");

[$statusCode] = lit_with_input("\e", ['SHELL' => '/bin/bash', 'HOME' => $worldPath], 'help');

assert_same(130, $statusCode);
assert_file_missing("$worldPath/lit/data/installation-id");
