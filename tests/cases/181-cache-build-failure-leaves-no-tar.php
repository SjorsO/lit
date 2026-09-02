<?php

require __DIR__.'/../test-helpers.php';

// Building a cached release must publish through a temporary file.
// A failing "tar" used to leave a half written ".tar" in the cache,
// and the next deploy would happily extract that broken file.

$worldPath = world_path();
$caseDir = "$worldPath/case";

$seedPath = "$caseDir/seed";
$remotePath = "$caseDir/origin-repo.git";
$remoteUrl = "file://$remotePath";

$gitCommand = ['git', '-c', 'user.email=lit@test', '-c', 'user.name=lit'];

run_process(['git', 'init', '--quiet', '--initial-branch=main', $seedPath], $caseDir);

file_put_contents("$seedPath/app.txt", "one\n");
run_process(['git', 'add', 'app.txt'], $seedPath);
run_process([...$gitCommand, 'commit', '--quiet', '-m', 'one'], $seedPath);

run_process(['git', 'clone', '--quiet', '--bare', $seedPath, $remotePath], $caseDir);

chdir($caseDir);

[$statusCode] = lit('init', $remoteUrl);

assert_same(0, $statusCode);

$projectPath = "$caseDir/origin-repo";

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

[$statusCode] = lit('enable-git-release-caching');

assert_same(0, $statusCode);

file_put_contents("$projectPath/hooks/before-caching.sh", "# no-op\n");

// Mock tar only after init, git needs a working tar for nothing but let's be safe
$realTarPath = trim((string) shell_exec('command -v tar'));

mkdir("$worldPath/bin");

// Writing an archive writes a few bytes and then fails, everything else is real
file_put_contents("$worldPath/bin/tar", <<<BASH
#!/bin/bash
previous=""

for argument in "\$@"; do
    if [ "\$previous" = "-cf" ] || [ "\$previous" = "-czf" ]; then
        echo "half written archive" > "\$argument"
        echo "tar: simulated failure" >&2
        exit 2
    fi

    previous="\$argument"
done

exec $realTarPath "\$@"
BASH."\n");

chmod("$worldPath/bin/tar", 0755);

[$statusCode, $output] = lit('deploy');

assert_same(2, $statusCode);

assert_string_contains($output, 'tar: simulated failure');
assert_string_contains($output, 'Finished with errors');

// The failed build must not leave anything behind
assert_same([], glob("$worldPath/lit/cached-releases/*.tar"));
assert_same([], glob("$worldPath/lit/cached-releases/*"));

// The deploy aborted at the cache build, it never got to a release
assert_string_not_contains($output, "Creating \"$projectPath/releases/1\"");
assert_string_not_contains($output, 'Releasing the new deployment');

assert_file_missing("$projectPath/current");
assert_same([], glob("$projectPath/releases/*"));

// Nothing about this run reached the lit state
assert_lit_state_value($projectPath, 'git_commit_sha', 'not deployed yet');

assert_string_contains(file_get_contents("$projectPath/logs/lit.log"), 'lit deploy → failed');
