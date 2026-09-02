<?php

/**
 * @var string $litBasePath
 * @var string $projectBasePath
 */

$sourceType = get_source_type($projectBasePath);

if ($sourceType !== 'git') {
    out("Release caching is only available when deploying from git\n");

    lit_exit(1);
}

$litState = read_lit_state($projectBasePath);

if (($litState['git_release_caching_enabled'] ?? false) === true) {
    out("Release caching for git is already enabled\n");

    lit_exit(1);
}

update_lit_state($projectBasePath, 'git_release_caching_enabled', true);

out("Release caching for git enabled\n");
out("\n");

if (! file_exists("$projectBasePath/hooks/before-caching.sh")) {
    copy("$litBasePath/stubs/hooks-for-git/before-caching.sh.stub", "$projectBasePath/hooks/before-caching.sh");

    $projectBaseName = basename($projectBasePath);

    out("Created new hook \"$projectBaseName/hooks/before-caching.sh\"\n");
    out("\n");
}

out("Review and update these hooks:\n");
out("- \"hooks/before-caching.sh\"\n");
out("- \"hooks/before-release.sh\"\n");
out("- \"hooks/after-release.sh\"\n");
out("\n");
