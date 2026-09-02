<?php

require __DIR__.'/../test-helpers.php';

// Test that a second deploy is blocked when another deploy is running

[$statusCode] = lit('init', 'https://github.com/SjorsO/lit.git');

assert_same(0, $statusCode);

$projectPath = world_path().'/case/lit';

// Make the before-release hook take 3 seconds
file_put_contents("$projectPath/hooks/before-release.sh", "sleep 3\n");
file_put_contents("$projectPath/hooks/after-release.sh", "\n");

file_put_contents("$projectPath/.env", "APP_KEY=test\n");

chdir($projectPath);

// Start first deploy in the background
$firstDeployProcess = proc_open(lit_command(['deploy']), [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $firstDeployPipes, $projectPath, lit_environment());

stream_set_blocking($firstDeployPipes[1], false);

// Wait 1 second for the first deploy to acquire the lock
sleep(1);

// Try to run second deploy while first is still running
[$secondStatusCode, $secondOutput] = lit('deploy');

// Second deploy should fail
assert_same(1, $secondStatusCode);

// Second deploy should mention another command is running
assert_same(<<<EXPECTED
Another Lit command is currently running for this project, aborting...
If this is wrong, manually run:
    rmdir "$projectPath/lit-is-currently-running"
EXPECTED, $secondOutput);

// Wait for first deploy to finish. On PHP < 8.3 the exit code is only valid on
// the proc_get_status() call that observes the process exit, so keep that result.
$firstDeployStatus = proc_get_status($firstDeployProcess);

while ($firstDeployStatus['running']) {
    stream_get_contents($firstDeployPipes[1]);

    usleep(100_000);

    $firstDeployStatus = proc_get_status($firstDeployProcess);
}

$firstStatusCode = $firstDeployStatus['exitcode'];

stream_get_contents($firstDeployPipes[1]);
fclose($firstDeployPipes[1]);
proc_close($firstDeployProcess);

// First deploy should succeed
assert_same(0, $firstStatusCode);

// Release should have been created by first deploy
assert_directory_exists("$projectPath/releases/1");

// Lock directory should be cleaned up
assert_file_missing("$projectPath/lit-is-currently-running");

// Log should contain both deploys in order: first deploy succeeded, then second was aborted
$logLines = explode("\n", rtrim(file_get_contents("$projectPath/logs/lit.log"), "\n"));

assert_string_contains($logLines[0] ?? '', 'lit deploy → deployed branch');
assert_string_contains($logLines[1] ?? '', 'lit deploy → aborted, another lit command is currently running');
