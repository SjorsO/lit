<?php

/**
 * Runs the first time Lit is used (when data/installation-id does not exist)
 *
 * @var string $litBasePath
 */

if (! is_dir("$litBasePath/data")) {
    mkdir("$litBasePath/data");
}

$homeDirectory = getenv('HOME') ?: '';

if (basename(getenv('SHELL') ?: '') === 'zsh') {
    $aliasFileCandidates = ['.zshrc', '.zprofile', '.profile'];
} else {
    $aliasFileCandidates = ['.bashrc', '.bash_profile', '.profile'];

    // A ".bash_aliases" only works when ".bashrc" sources it
    if (str_contains((string) @file_get_contents("$homeDirectory/.bashrc"), '.bash_aliases')) {
        array_unshift($aliasFileCandidates, '.bash_aliases');
    }
}

$aliasFile = '';

foreach ($aliasFileCandidates as $aliasFileCandidate) {
    if (is_file("$homeDirectory/$aliasFileCandidate")) {
        $aliasFile = "$homeDirectory/$aliasFileCandidate";

        break;
    }
}

// The alias already exists, install silently and keep running lit
if ($aliasFile !== '' && str_contains((string) @file_get_contents($aliasFile), 'lit.php')) {
    file_put_contents("$litBasePath/data/installation-id", time().':'.uuid()."\n");

    return;
}

// Replace the home directory with a "~"
function pretty_path(string $path): string
{
    $homeDirectory = getenv('HOME') ?: '';

    if ($homeDirectory !== '' && str_starts_with($path, $homeDirectory)) {
        return '~'.substr($path, strlen($homeDirectory));
    }

    return $path;
}

// Add box edges to the line, but only if the whole output fits in the box
function boxed_line(string $line = ''): string
{
    if (! $GLOBALS['outputFitsInBox']) {
        return $line;
    }

    // Color codes and multibyte characters have no extra width
    $lineWidth = strlen(preg_replace(['/\e\[[0-9;]*m/', '/[\x80-\xBF]/'], '', $line));

    return '│'.$line.str_repeat(' ', max(0, 72 - $lineWidth)).'│';
}

function path_needs_quotes(string $path): bool
{
    return ! preg_match('#^[A-Za-z0-9_@%+=:,./~-]+$#', $path);
}

function alias_command(string $scriptPath): string
{
    $aliasBody = "php '".str_replace("'", "'\\''", $scriptPath)."'";

    // Escape what the outer double quotes would expand before the alias is even stored
    return 'alias lit="'.addcslashes($aliasBody, '$`"\\').'"';
}

$litScriptPath = "$litBasePath/lit.php";

$aliasCommand = alias_command($litScriptPath);

$displayedAliasFile = pretty_path($aliasFile);

$prettyScriptPath = pretty_path($litScriptPath);

$displayedAliasCommand = path_needs_quotes($prettyScriptPath)
    ? $aliasCommand
    : "alias lit=\"php $prettyScriptPath\"";

// Long paths can make lines too wide for the box
$outputFitsInBox = strlen("    $displayedAliasFile") <= 72 && strlen("    $displayedAliasCommand") <= 72;

echo "                           ┌──────────────────┐\n";
echo "╭──────────────────────────┤  Welcome to Lit  ├──────────────────────────╮\n";

// The corner needs one space less inside the box, that keeps it aligned
if ($outputFitsInBox) {
    echo "│                          └──────────────────┘                          │\n";
} else {
    echo "                           └──────────────────┘\n";
}

$menuAnswer = '';

if ($aliasFile === '') {
    echo boxed_line("  Unable to find shell config, so no alias was added automatically.")."\n";
    echo boxed_line()."\n";
    echo boxed_line("  You can add the following alias manually:")."\n";
    echo boxed_line()."\n";
    echo boxed_line("    $displayedAliasCommand")."\n";
    echo boxed_line()."\n";
    echo "╰────────────────────────────────────────────────────────────────────────╯\n";
} else {
    echo boxed_line("  Add an alias for Lit?")."\n";
    echo boxed_line()."\n";
    echo boxed_line("  File:")."\n";
    echo boxed_line("    $displayedAliasFile")."\n";
    echo boxed_line()."\n";
    echo boxed_line("  Alias:")."\n";
    echo boxed_line("    $displayedAliasCommand")."\n";
    echo boxed_line()."\n";
    echo "╰────────────────────────────────────────────────────────────────────────╯\n";

    // The menu draws itself below the closed box
    $menuAnswer = yes_no_menu();

    if ($menuAnswer === 'y') {
        $fileContents = (string) @file_get_contents($aliasFile);

        $separator = "\n\n";

        if ($fileContents === '' || str_ends_with($fileContents, "\n\n")) {
            $separator = '';
        } elseif (str_ends_with($fileContents, "\n")) {
            $separator = "\n";
        }

        // Appending leaves the rest of the config alone when the write fails
        if (@file_put_contents($aliasFile, "$separator$aliasCommand\n", FILE_APPEND | LOCK_EX) === false) {
            $menuAnswer = 'failed';
        }
    }
}

echo "\n";
echo "╭────────────────────────────────────────────────────────────────────────╮\n";

if ($menuAnswer === 'y') {
    echo boxed_line('  ✓ Alias added. Restart your shell to start using it.')."\n";
    echo boxed_line()."\n";
}

if ($menuAnswer === 'n') {
    echo boxed_line("  Not adding alias.")."\n";
    echo boxed_line()."\n";
}

if ($menuAnswer === 'failed') {
    echo boxed_line("  ✗ Unable to write to \"$displayedAliasFile\".")."\n";
    echo boxed_line()."\n";
    echo boxed_line("  You can add the following alias manually:")."\n";
    echo boxed_line()."\n";
    echo boxed_line("    $displayedAliasCommand")."\n";
    echo boxed_line()."\n";
}

echo boxed_line("  Setup complete. Rerun your command to continue.")."\n";
echo "╰────────────────────────────────────────────────────────────────────────╯\n";
echo "\n";

file_put_contents("$litBasePath/data/installation-id", time().':'.uuid()."\n");

lit_exit(0);
