<?php

require __DIR__.'/../test-helpers.php';

// Test that the installer escapes the alias path, and only writes to a config the shell sources

$worldPath = world_path();

// Test 1: A lit directory with a space in its name, inside the home directory
$spacedHomePath = "$worldPath/spaced-home";

mkdir($spacedHomePath);

$spacedLitPath = "$spacedHomePath/lit dir";

run_process(['cp', '-R', "$worldPath/lit", $spacedLitPath], $worldPath);

unlink("$spacedLitPath/data/installation-id");

file_put_contents("$spacedHomePath/.zshrc", "# fresh zshrc\n");

$spacedEnvironment = lit_environment(['SHELL' => '/bin/zsh', 'HOME' => $spacedHomePath]);

[$statusCode, $output] = run_process([PHP_BINARY, "$spacedLitPath/lit.php", 'help'], $worldPath, $spacedEnvironment, 'y');

assert_same(0, $statusCode);

// A "~" only expands unquoted, so a path needing quotes is shown in full
assert_string_contains(normalize_output($output), "alias lit=\"php '$spacedLitPath/lit.php'\"");
assert_string_not_contains($output, 'php ~/');

// The shell parses the alias body again when the alias runs, so the path stays quoted inside
assert_same(<<<EXPECTED
# fresh zshrc

alias lit="php '$spacedLitPath/lit.php'"

EXPECTED, file_get_contents("$spacedHomePath/.zshrc"));

// A real shell resolves the alias to the spaced path
[$statusCode, $output] = run_process(
    // Aliases expand as a line is read, so each command needs its own line
    ['bash', '-c', "shopt -s expand_aliases\nsource \"$spacedHomePath/.zshrc\"\nlit help"],
    $worldPath,
    $spacedEnvironment,
);

assert_same(0, $statusCode);
assert_output_is_help_text($output);

// Test 2: A zsh user gets ".zshrc", even when bash config files exist
$zshHomePath = "$worldPath/zsh-home";

mkdir($zshHomePath);

file_put_contents("$zshHomePath/.bashrc", "# bashrc\nsource ~/.bash_aliases\n");
file_put_contents("$zshHomePath/.bash_aliases", "# bash aliases\n");
file_put_contents("$zshHomePath/.zshrc", "# zshrc\n");

unlink("$worldPath/lit/data/installation-id");

[$statusCode] = lit_with_input('y', ['SHELL' => '/bin/zsh', 'HOME' => $zshHomePath], 'help');

assert_same(0, $statusCode);

assert_string_contains(file_get_contents("$zshHomePath/.zshrc"), 'alias lit=');
assert_string_not_contains(file_get_contents("$zshHomePath/.bash_aliases"), 'alias lit=');
assert_string_not_contains(file_get_contents("$zshHomePath/.bashrc"), 'alias lit=');

// Test 3: A ".bash_aliases" that nothing sources is skipped in favour of ".bashrc"
$bashHomePath = "$worldPath/bash-home";

mkdir($bashHomePath);

file_put_contents("$bashHomePath/.bashrc", "# bashrc\n");
file_put_contents("$bashHomePath/.bash_aliases", "# bash aliases\n");

unlink("$worldPath/lit/data/installation-id");

[$statusCode] = lit_with_input('y', ['SHELL' => '/bin/bash', 'HOME' => $bashHomePath], 'help');

assert_same(0, $statusCode);

assert_string_contains(file_get_contents("$bashHomePath/.bashrc"), 'alias lit=');
assert_string_not_contains(file_get_contents("$bashHomePath/.bash_aliases"), 'alias lit=');

// Test 4: A ".bash_aliases" that ".bashrc" sources is used
$sourcedHomePath = "$worldPath/sourced-home";

mkdir($sourcedHomePath);

file_put_contents("$sourcedHomePath/.bashrc", "# bashrc\nsource ~/.bash_aliases\n");
file_put_contents("$sourcedHomePath/.bash_aliases", "# bash aliases\n");

unlink("$worldPath/lit/data/installation-id");

[$statusCode] = lit_with_input('y', ['SHELL' => '/bin/bash', 'HOME' => $sourcedHomePath], 'help');

assert_same(0, $statusCode);

assert_string_contains(file_get_contents("$sourcedHomePath/.bash_aliases"), 'alias lit=');
assert_string_not_contains(file_get_contents("$sourcedHomePath/.bashrc"), 'alias lit=');

// Test 5: A config that cannot be written is reported, and left untouched
if (posix_geteuid() !== 0) {
    $readOnlyHomePath = "$worldPath/read-only-home";

    mkdir($readOnlyHomePath);

    file_put_contents("$readOnlyHomePath/.bashrc", "# read only bashrc\n");

    chmod("$readOnlyHomePath/.bashrc", 0444);

    unlink("$worldPath/lit/data/installation-id");

    [$statusCode, $output] = lit_with_input('y', ['SHELL' => '/bin/bash', 'HOME' => $readOnlyHomePath], 'help');

    assert_same(0, $statusCode);

    $output = normalize_output($output);

    assert_string_contains($output, '✗ Unable to write to "~/.bashrc".');
    assert_string_contains($output, 'You can add the following alias manually:');

    // The config still holds only what it started with
    assert_same("# read only bashrc\n", file_get_contents("$readOnlyHomePath/.bashrc"));
}

// Test 6: A path with a quote and a dollar sign still resolves through a real shell
$weirdLitPath = "$worldPath/lit's \$dir";

run_process(['cp', '-R', "$worldPath/lit", $weirdLitPath], $worldPath);

unlink("$weirdLitPath/data/installation-id");

$weirdHomePath = "$worldPath/weird-home";

mkdir($weirdHomePath);

file_put_contents("$weirdHomePath/.zshrc", "# fresh zshrc\n");

$weirdEnvironment = lit_environment(['SHELL' => '/bin/zsh', 'HOME' => $weirdHomePath]);

[$statusCode] = run_process([PHP_BINARY, "$weirdLitPath/lit.php", 'help'], $worldPath, $weirdEnvironment, 'y');

assert_same(0, $statusCode);

[$statusCode, $output] = run_process(
    ['bash', '-c', "shopt -s expand_aliases\nsource \"$weirdHomePath/.zshrc\"\nlit help"],
    $worldPath,
    $weirdEnvironment,
);

assert_same(0, $statusCode);
assert_output_is_help_text($output);
