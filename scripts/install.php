<?php

/**
 * @var string $litBasePath
 */

// Runs the first time Lit is used (when data/installation-id does not exist)

if (! is_dir("$litBasePath/data")) {
    mkdir("$litBasePath/data");
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

    while (true) {
        fwrite(STDERR, "\033[1A\r\033[K");

        if ($current === 0) {
            fwrite(STDERR, "  \033[32m[Yes]\033[0m    [No]\n");
        } else {
            fwrite(STDERR, "  [Yes]    \033[32m[No]\033[0m\n");
        }

        $key = fread(STDIN, 1);

        if ($key === '' || $key === false) {
            lit_exit(130);
        }

        if ($key === "\e") {
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

$homeDirectory = getenv('HOME') ?: '';

$aliasFiles = [
    "$homeDirectory/.bash_aliases",
    "$homeDirectory/.zsh_aliases",
    "$homeDirectory/.zshrc",
    "$homeDirectory/.bashrc",
    "$homeDirectory/.bash_profile",
    "$homeDirectory/.profile",
];

$aliasFile = '';

foreach ($aliasFiles as $aliasFileCandidate) {
    if (is_file($aliasFileCandidate)) {
        $aliasFile = $aliasFileCandidate;

        break;
    }
}

$aliasCommand = "alias lit=\"php $litBasePath/lit.php\"";

echo "                           ┌──────────────────┐\n";
echo "╭──────────────────────────┤  Welcome to Lit  ├──────────────────────────╮\n";
echo "                           └──────────────────┘\n";

if ($aliasFile === '') {
    echo "  Normally Lit would ask you if you want to add an alias, but Lit\n";
    echo "  can't find the file to put the alias in.\n";
    echo "\n";
    echo "  You can add the following alias manually:\n";
    echo "\n";
    echo "    $aliasCommand\n";
} elseif (str_contains(file_get_contents($aliasFile), 'lit.php')) {
    echo "  You already have an alias for Lit.\n";
} else {
    echo "  Would you like to add an alias for Lit?\n";
    echo "\n";
    echo "  File:\n";
    echo "    $aliasFile\n";
    echo "\n";
    echo "  Alias:\n";
    echo "    $aliasCommand\n";
    echo "\n";
    echo "  Add alias?\n";
    echo "\n";
    echo "\n";

    if (yes_no_menu() === 'y') {
        file_put_contents($aliasFile, "\n$aliasCommand\n", FILE_APPEND);

        echo "\n";
        echo "  Alias added, restart your shell to start using \"lit\".\n";
    } else {
        echo "\n";
        echo "  Not adding alias.\n";
    }
}

echo "\n";
echo "├────────────────────────────────────────────────────────────────────────┤\n";
echo "\n";
echo "  All done, you're ready to use Lit.\n";
echo "\n";
echo "╰────────────────────────────────────────────────────────────────────────╯\n";
echo "\n";

file_put_contents("$litBasePath/data/installation-id", uuid()."\n");
