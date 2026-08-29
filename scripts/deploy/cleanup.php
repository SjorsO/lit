<?php

function register_deploy_cleanup(stdClass $state, string $projectBasePath, string $sourceType, int $startedAt): void
{
    register_cleanup(function (int $exitCode) use ($state, $projectBasePath, $sourceType, $startedAt) {
        chdir($projectBasePath);

        if ($state->releaseDirectoryCreated && ! $state->wasReleased) {
            out("Deleting new but unreleased release directory \"$state->newReleaseDirectory\"\n");

            delete_directory($state->newReleaseDirectory);
        }

        if ($state->tempDirectoryPath !== '' && is_dir($state->tempDirectoryPath)) {
            delete_directory($state->tempDirectoryPath);
        }

        if ($state->stagingDirectoryPath !== '' && is_dir($state->stagingDirectoryPath)) {
            delete_directory($state->stagingDirectoryPath);
        }

        // This might still exist depending on how we aborted
        delete_file("$projectBasePath/bundle-for-current-deployment.tar");

        if ($GLOBALS['current_run_result'] === '') {
            $shortCommit = substr($state->currentCommit, 0, 11);

            $refDescription = $state->currentRefType === 'commit'
                ? "commit $shortCommit"
                : "$state->currentRefType \"$state->currentRef\" (commit: $shortCommit)";

            $hadErrors = $exitCode !== 0;

            $result = match (true) {
                $state->wasReleased && $hadErrors && $sourceType === 'git' => "had errors, still deployed $refDescription",
                $state->wasReleased && $hadErrors && $sourceType === 'bundle' => "had errors, still deployed bundle (hash: $state->newBundleHash)",
                $state->wasReleased && $sourceType === 'git' => "deployed $refDescription",
                $state->wasReleased && $sourceType === 'bundle' => "deployed bundle (hash: $state->newBundleHash)",
                $state->releaseDirectoryCreated => 'failed, deployment was not released',
                $hadErrors => 'failed',
                default => null,
            };

            if ($result !== null) {
                $GLOBALS['current_run_result'] = $result;
            }
        }

        if ($state->wasReleased && $exitCode !== 0) {
            out(">\n");
            out("> Warning: The new deployment was still released!\n");
            out(">\n");
        }

        if ($exitCode !== 0) {
            if (file_exists("$projectBasePath/hooks/on-failure.sh")) {
                if (run_command(['bash', '-e', "$projectBasePath/hooks/on-failure.sh", $projectBasePath, $state->wasReleased ? 'true' : 'false']) !== 0) {
                    out("The on-failure hook failed\n");
                }
            } else {
                out("Wanted to run \"$projectBasePath/hooks/on-failure.sh\" but it does not exist\n");
            }
        }

        $prettyRuntime = pretty_runtime(current_time_in_ms() - $startedAt);

        $finishedWord = $exitCode !== 0 ? 'with errors' : 'successfully';

        out("Finished $finishedWord (in $prettyRuntime)\n");
    });
}
