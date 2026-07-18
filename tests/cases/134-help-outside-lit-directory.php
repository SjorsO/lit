<?php

require __DIR__.'/../test-helpers.php';

// Test that lit help works outside of a lit directory

$worldPath = world_path();

$expectedOutput = <<<'EXPECTED'
╭──────────────────────────────────────────────────────────────────────────────╮
│ usage: lit <command>                                                         │
│                                                                              │
│ Common Lit commands:                                                         │
│                                                                              │
│   init <url> [name]    Initialize a new Lit directory from git or a bundle   │
│   deploy               Run a new deployment                                  │
│   checkout <branch>    Git checkout the given branch and deploy it           │
│                                                                              │
│ Other commands:                                                              │
│                                                                              │
│   flush-opcache                  Flush PHP-FPM OPcache                       │
│   opcache-status [--json]        Show PHP-FPM OPcache status                 │
│   enable-git-release-caching     For faster deployments of the same commit   │
│   disable-git-release-caching    Disable git release caching                 │
│                                                                              │
│ For more info, visit: https://github.com/SjorsO/lit                          │
╰──────────────────────────────────────────────────────────────────────────────╯
EXPECTED;

// We're in $worldPath/case which is not a lit directory
[$statusCode, $output] = lit('help');

// Help should work even outside a lit directory
assert_same(0, $statusCode);
assert_same($expectedOutput, $output);

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
assert_same($expectedOutput, $output);

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
