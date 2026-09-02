<?php

// Lit v1 stored state in separate files. Lit v2 uses a single "lit.json".
// This migrates a v1 project automatically. The old files get deleted.
function migrate_state_from_v1_to_v2(string $projectBasePath): void
{
    if (file_exists("$projectBasePath/git-repository-url")) {
        $litState = [
            'git_repository_url' => get_file_value("$projectBasePath/git-repository-url"),
            'git_ref' => file_exists("$projectBasePath/git-branch") ? get_file_value("$projectBasePath/git-branch") : '',
            'git_ref_type' => 'branch',
            'git_commit_sha' => file_exists("$projectBasePath/git-commit") ? get_file_value("$projectBasePath/git-commit") : 'not deployed yet',
            'git_release_caching_enabled' => file_exists("$projectBasePath/git-release-caching-enabled"),
        ];
    } else {
        $litState = [
            'bundle_url' => get_file_value("$projectBasePath/bundle-url"),
            'bundle_hash' => file_exists("$projectBasePath/bundle-hash") ? get_file_value("$projectBasePath/bundle-hash") : 'not deployed yet',
        ];
    }

    write_lit_state($projectBasePath, $litState);

    foreach (['git-repository-url', 'git-branch', 'git-commit', 'git-release-caching-enabled', 'bundle-url', 'bundle-hash'] as $oldStateFile) {
        delete_file("$projectBasePath/$oldStateFile");
    }

    out("Migrated Lit v1 state files into \"lit.json\"\n");
}
