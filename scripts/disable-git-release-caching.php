<?php

/**
 * @var string $projectBasePath
 */

$litState = read_lit_state($projectBasePath);

if (($litState['git_release_caching_enabled'] ?? false) !== true) {
    out("Release caching for git is already disabled\n");

    lit_exit(1);
}

update_lit_state($projectBasePath, 'git_release_caching_enabled', false);

out("Release caching for git disabled\n");
out("\n");
out("Review and update these hooks:\n");
out("- \"hooks/before-release.sh\"\n");
out("- \"hooks/after-release.sh\"\n");
out("\n");

if (file_exists("$projectBasePath/hooks/before-caching.sh")) {
    $projectBaseName = basename($projectBasePath);

    out("This hook will not be used anymore: \"$projectBaseName/hooks/before-caching.sh\"\n");
    out("\n");
}
