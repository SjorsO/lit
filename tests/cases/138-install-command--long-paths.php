<?php

require __DIR__.'/../test-helpers.php';

// Test the install flow with long paths, the alias line does not fit inside the box

$worldPath = world_path();

// Remove installation-id to trigger install on next lit command
unlink("$worldPath/lit/data/installation-id");

// The home directory is not a parent of the lit directory, so the alias is printed with its full long path
$homePath = "$worldPath/home";

mkdir($homePath);

// Create a fake shell config file for alias testing
$fakeRcFile = "$homePath/.bashrc";

file_put_contents($fakeRcFile, "# fake bashrc\n");

chdir($worldPath);

// Test 1: Install with "no" to the alias prompt
[$statusCode, $output] = lit_with_input('n', ['HOME' => $homePath], 'help');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
                           ┌──────────────────┐
╭──────────────────────────┤  Welcome to Lit  ├──────────────────────────╮
                           └──────────────────┘
  Add an alias for Lit?

  File:
    ~/.bashrc

  Alias:
    alias lit="php $worldPath/lit/lit.php"

╰────────────────────────────────────────────────────────────────────────╯
  (●) Yes    ( ) No                                ←/→ + enter to select

╭────────────────────────────────────────────────────────────────────────╮
  Not adding alias.

  Setup complete. Rerun your command to continue.
╰────────────────────────────────────────────────────────────────────────╯
EXPECTED, $output);

// Alias should NOT be added to bashrc
assert_string_not_contains(file_get_contents($fakeRcFile), 'lit.php');

// Test 2: Reset and test with "yes" to the alias prompt
unlink("$worldPath/lit/data/installation-id");

file_put_contents($fakeRcFile, "# fresh bashrc\n");

[$statusCode, $output] = lit_with_input('y', ['HOME' => $homePath], 'help');

assert_same(0, $statusCode);

$output = normalize_output($output);

assert_same(<<<EXPECTED
                           ┌──────────────────┐
╭──────────────────────────┤  Welcome to Lit  ├──────────────────────────╮
                           └──────────────────┘
  Add an alias for Lit?

  File:
    ~/.bashrc

  Alias:
    alias lit="php $worldPath/lit/lit.php"

╰────────────────────────────────────────────────────────────────────────╯
  (●) Yes    ( ) No                                ←/→ + enter to select

╭────────────────────────────────────────────────────────────────────────╮
  ✓ Alias added. Restart your shell to start using it.

  Setup complete. Rerun your command to continue.
╰────────────────────────────────────────────────────────────────────────╯
EXPECTED, $output);

// Alias should be added to bashrc
assert_string_contains(file_get_contents($fakeRcFile), "alias lit=\"php $worldPath/lit/lit.php\"");

// Test 3: If no rc file exists, show how to add the alias manually
unlink("$worldPath/lit/data/installation-id");

mkdir("$worldPath/empty-home");

[$statusCode, $output] = lit_with_input('', ['HOME' => "$worldPath/empty-home"], 'help');

assert_same(0, $statusCode);

assert_same(<<<EXPECTED
                           ┌──────────────────┐
╭──────────────────────────┤  Welcome to Lit  ├──────────────────────────╮
                           └──────────────────┘
  Unable to find shell config, so no alias was added automatically.

  You can add the following alias manually:

    alias lit="php $worldPath/lit/lit.php"

╰────────────────────────────────────────────────────────────────────────╯

╭────────────────────────────────────────────────────────────────────────╮
  Setup complete. Rerun your command to continue.
╰────────────────────────────────────────────────────────────────────────╯
EXPECTED, $output);

assert_file_exists("$worldPath/lit/data/installation-id");
