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

$aliasFile = '';

foreach ([
    "$homeDirectory/.bash_aliases",
    "$homeDirectory/.zsh_aliases",
    "$homeDirectory/.zshrc",
    "$homeDirectory/.bashrc",
    "$homeDirectory/.bash_profile",
    "$homeDirectory/.profile",
] as $aliasFileCandidate) {
    if (is_file($aliasFileCandidate)) {
        $aliasFile = $aliasFileCandidate;

        break;
    }
}

// The alias already exists, install silently and keep running lit
if ($aliasFile !== '' && str_contains(file_get_contents($aliasFile), 'lit.php')) {
    file_put_contents("$litBasePath/data/installation-id", time().':'.uuid()."\n");

    return;
}

$stdinIsTty = function_exists('posix_isatty') && posix_isatty(STDIN);
$stdoutIsTty = function_exists('posix_isatty') && posix_isatty(STDOUT);

if ($stdinIsTty) {
    shell_exec('stty -icanon -echo');
}

register_cleanup(function () use ($stdinIsTty, $stdoutIsTty) {
    if ($stdinIsTty) {
        shell_exec('stty icanon echo');
    }

    // Restore cursor (only if we have a terminal)
    if ($stdoutIsTty) {
        echo "\033[?25h";
    }
});

// Hide cursor (only if we have a terminal)
if ($stdoutIsTty) {
    echo "\033[?25l";
}

function yes_no_menu(): string
{
    $current = 0;
    $firstDraw = true;

    while (true) {
        // Redraws jump back up to the menu line
        if (! $firstDraw) {
            fwrite(STDERR, "\033[1A");
        }

        $firstDraw = false;

        fwrite(STDERR, "\r\033[K");

        // Padded so the instructions end 2 spaces before the box edge above
        $instructions = str_repeat(' ', 32)."\033[2m←/→ + enter to select\033[0m";

        if ($current === 0) {
            fwrite(STDERR, "  \033[32m(●) Yes\033[0m    ( ) No$instructions\n");
        } else {
            fwrite(STDERR, "  ( ) Yes    \033[32m(●) No\033[0m$instructions\n");
        }

        $key = fread(STDIN, 1);

        if ($key === '' || $key === false || $key === 'q' || $key === 'Q') {
            lit_exit(130);
        }

        if ($key === "\e") {
            $read = [STDIN];
            $write = null;
            $except = null;

            // exit if escape is pressed (escape has no bytes after it, arrow keys do)
            if (stream_select($read, $write, $except, 0, 50_000) === 0) {
                lit_exit(130);
            }

            $arrowKey = fread(STDIN, 2);

            if ($arrowKey === '[C') {
                $current = ($current + 1) % 2;
            } elseif ($arrowKey === '[D') {
                $current = ($current + 1) % 2;
            }
        } elseif ($key === "\n" || $key === "\r") {
            return $current === 0 ? 'y' : 'n';
        } elseif ($key === 'y' || $key === 'Y') {
            return 'y';
        } elseif ($key === 'n' || $key === 'N') {
            return 'n';
        }
    }
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

    return '│'.$line.str_repeat(' ', 72 - $lineWidth).'│';
}

$aliasCommand = "alias lit=\"php $litBasePath/lit.php\"";

// Only used for printing, the real paths are used everywhere else
$displayedAliasFile = pretty_path($aliasFile);
$displayedAliasCommand = 'alias lit="php '.pretty_path("$litBasePath/lit.php").'"';

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
        $fileContents = rtrim(file_get_contents($aliasFile));

        file_put_contents($aliasFile, "$fileContents\n\n$aliasCommand\n");
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

echo boxed_line("  Setup complete. Rerun your command to continue.")."\n";
echo "╰────────────────────────────────────────────────────────────────────────╯\n";
echo "\n";

file_put_contents("$litBasePath/data/installation-id", time().':'.uuid()."\n");

lit_exit(0);
