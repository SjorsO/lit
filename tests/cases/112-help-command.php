<?php

require __DIR__.'/../test-helpers.php';

// Test the lit help command

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

chdir(world_path().'/case/lit');

[$statusCode, $output] = lit('help');

assert_same(0, $statusCode);

assert_same(<<<'EXPECTED'
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
EXPECTED, $output);
