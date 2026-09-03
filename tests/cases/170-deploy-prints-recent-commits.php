<?php

require __DIR__.'/../test-helpers.php';

// A deploy prints the last 10 commits.
// The "─▶" arrow marks the commit being deployed.
// The list has an empty line before and after it.
// Tags show next to their commit.
// If the old commit is in the list, a line runs from it up to the new one.

$worldPath = world_path();
$caseDir = "$worldPath/case";

$seedPath = "$caseDir/seed";
$remotePath = "$caseDir/origin-repo.git";

// "file://" makes git use the real transport, a plain path would ignore "--depth"
$remoteUrl = "file://$remotePath";

$gitCommand = ['git', '-c', 'user.email=lit@test', '-c', 'user.name=lit'];

run_process(['git', 'init', '--quiet', '--initial-branch=main', $seedPath], $caseDir);

$commits = [];
$shortHashes = [];

foreach (['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve'] as $message) {
    file_put_contents("$seedPath/app.txt", "$message\n");
    run_process(['git', 'add', 'app.txt'], $seedPath);
    run_process([...$gitCommand, 'commit', '--quiet', '-m', $message], $seedPath);

    [, $commit] = run_process(['git', 'rev-parse', 'HEAD'], $seedPath);

    $commits[$message] = $commit;
    $shortHashes[$message] = substr($commit, 0, 7);
}

// Tag a commit, the tag must show in the deploy log
run_process(['git', 'tag', 'v1.0', $commits['ten']], $seedPath);

run_process(['git', 'clone', '--quiet', '--bare', $seedPath, $remotePath], $caseDir);

// Lit fetches commit SHAs, the remote must allow that (GitHub and GitLab do)
run_process(['git', '-C', $remotePath, 'config', 'uploadpack.allowAnySHA1InWant', 'true'], $caseDir);

chdir($caseDir);

[$statusCode] = lit('init', $remoteUrl);

assert_same(0, $statusCode);

$projectPath = "$caseDir/origin-repo";

neutralize_hooks($projectPath);
file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// Deploy the branch, the log shows the 10 newest commits
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

// An empty line sits before the list
assert_string_contains($output, "Cloning repository... \n\n─▶ {$shortHashes['twelve']} twelve");

// An empty line sits after the list
assert_string_contains($output, <<<EXPECTED
─▶ {$shortHashes['twelve']} twelve
   {$shortHashes['eleven']} eleven
   {$shortHashes['ten']} (tag: v1.0) ten
   {$shortHashes['nine']} nine
   {$shortHashes['eight']} eight
   {$shortHashes['seven']} seven
   {$shortHashes['six']} six
   {$shortHashes['five']} five
   {$shortHashes['four']} four
   {$shortHashes['three']} three

Creating a symlink to the storage directory
EXPECTED);

// Only 10 commits are shown
assert_string_not_contains($output, "{$shortHashes['two']} two");
assert_string_not_contains($output, "{$shortHashes['one']} one");

// Checkout an old commit, the arrow points at that commit
[$statusCode, $output] = lit('checkout', $commits['three']);

assert_same(0, $statusCode);

assert_string_contains($output, <<<EXPECTED
─▶ {$shortHashes['three']} three
   {$shortHashes['two']} two
   {$shortHashes['one']} one

Creating a symlink to the storage directory
EXPECTED);

// The normalizer removes the commit lines, other tests stay stable
$output = normalize_output($output);

assert_same(<<<EXPECTED
Switching to "HASH"...
Creating "$projectPath/releases/2" for the new release...
Cloning repository...
Creating a symlink to the storage directory
Creating a symlink to the .env file
Releasing the new deployment "$projectPath/releases/2"
Finished successfully (in X seconds)
EXPECTED, $output);

// Checkout main again, the old commit "three" is in the list
// A line runs from the old commit up to the new one
[$statusCode, $output] = lit('checkout', 'main');

assert_same(0, $statusCode);

assert_string_contains($output, <<<EXPECTED
┌▶ {$shortHashes['twelve']} twelve
│  {$shortHashes['eleven']} eleven
│  {$shortHashes['ten']} (tag: v1.0) ten
│  {$shortHashes['nine']} nine
│  {$shortHashes['eight']} eight
│  {$shortHashes['seven']} seven
│  {$shortHashes['six']} six
│  {$shortHashes['five']} five
│  {$shortHashes['four']} four
└─ {$shortHashes['three']} three

Creating a symlink to the storage directory
EXPECTED);

// The normalizer also removes the line block
$output = normalize_output($output);

assert_string_contains($output, "Cloning repository...\nCreating a symlink to the storage directory");

// Deploying again is skipped, the list still shows what is live
// The old commit is the new commit, so only the "─▶" arrow shows
[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

$elevenChars = substr($commits['twelve'], 0, 11);

assert_string_contains($output, <<<EXPECTED
Latest commit of "main" is already deployed ($elevenChars)

─▶ {$shortHashes['twelve']} twelve
   {$shortHashes['eleven']} eleven
   {$shortHashes['ten']} (tag: v1.0) ten
   {$shortHashes['nine']} nine
   {$shortHashes['eight']} eight
   {$shortHashes['seven']} seven
   {$shortHashes['six']} six
   {$shortHashes['five']} five
   {$shortHashes['four']} four
   {$shortHashes['three']} three

Run "lit deploy --force" to redeploy
EXPECTED);

// The normalizer removes this block too
assert_same(<<<EXPECTED
Reading branch "main" of "$remoteUrl"...
Latest commit of "main" is already deployed (COMMIT)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)
EXPECTED, normalize_output($output));

// Without ".git" in the live release there is nothing to list
// Git must not walk up and log a parent repository (the test world lives inside one)
run_process(['rm', '-rf', realpath("$projectPath/current").'/.git'], $projectPath);

[$statusCode, $output] = lit('deploy');

assert_same(0, $statusCode);

assert_string_not_contains($output, '▶');

assert_same(<<<EXPECTED
Reading branch "main" of "$remoteUrl"...
Latest commit of "main" is already deployed (COMMIT)
Run "lit deploy --force" to redeploy
Finished successfully (in X seconds)
EXPECTED, normalize_output($output));
