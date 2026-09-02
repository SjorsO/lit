<?php

require __DIR__.'/../test-helpers.php';

// Reading HEAD of a fresh clone can fail. The deploy must abort right there.
// Continuing would write an empty commit into lit.json, and the next deploy
// would then think nothing is deployed yet.

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$worldPath = world_path();
$projectPath = "$worldPath/case/lit";

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

neutralize_hooks($projectPath);

chdir($projectPath);

// Mock git only after init, init needs a working git
$realGitPath = trim((string) shell_exec('command -v git'));

mkdir("$worldPath/bin");

// Pass everything through to the real git, except the "rev-parse HEAD" of the new clone
file_put_contents("$worldPath/bin/git", <<<BASH
#!/bin/bash
seen_rev_parse=false
seen_head=false

for argument in "\$@"; do
    [ "\$argument" = "rev-parse" ] && seen_rev_parse=true
    [ "\$argument" = "HEAD" ] && seen_head=true
done

if [ "\$seen_rev_parse" = true ] && [ "\$seen_head" = true ]; then
    echo "fatal: simulated rev-parse failure" >&2
    exit 128
fi

exec $realGitPath "\$@"
BASH."\n");

chmod("$worldPath/bin/git", 0755);

[$deployStatusCode, $output] = lit('deploy');

assert_same(128, $deployStatusCode);

assert_string_contains($output, 'Failed to read the commit of the new release');
assert_string_contains($output, 'Finished with errors');

// The deploy never went live
assert_file_missing("$projectPath/current");
assert_same([], glob("$projectPath/releases/*"));

// An empty commit must never reach the lit state
assert_lit_state_value($projectPath, 'git_commit_sha', 'not deployed yet');

assert_string_contains(file_get_contents("$projectPath/logs/lit.log"), 'failed (could not read the commit)');
