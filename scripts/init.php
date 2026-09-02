<?php

/**
 * When you run `lit init <url> [project name]`:
 * - If a [project name] is passed in, that directory is used.
 * - If [project name] is empty or ".", the current directory is used if it is a Laravel project.
 * - If the current directory is not a Laravel project, and no [project name] is specified, Lit will
 * use the project name from the <url>.
 * - If the directory that Lit will use is not empty, and not a Laravel project, then Lit aborts.
 *
 * Lit can init in:
 * - An empty directory
 * - A directory containing a Lit project (to update the git/bundle url)
 * - A directory containing a zero downtime structure Laravel project (containing a ".env" file and
 * "storage" directory, and "releases" directory).
 * - A directory containing a non-zero downtime structure Laravel project (containing the "artisan"
 * and "composer.json" files, but missing the "releases" directory).
 *
 * @var string $litBasePath
 * @var string $projectBasePath
 * @var string[] $arguments
 */

$sourceUrl = $arguments[1] ?? '';
$customProjectName = $arguments[2] ?? '';

if ($sourceUrl === '' || isset($arguments[3])) {
    out("usage: lit init <url> [project-name]\n");
    out("\n");
    out("Examples:\n");
    out("  lit init https://github.com/user/repo.git\n");
    out("  lit init https://github.com/user/repo.git my-project\n");
    out("  lit init https://example.com/releases/app.tar.gz\n");

    lit_exit(1);
}

$sourceType = str_ends_with($sourceUrl, '.git') || str_starts_with($sourceUrl, 'git@') ? 'git' : 'bundle';

require_once "$litBasePath/scripts/deploy-key.php";

function is_existing_zero_downtime_project(string $path): bool
{
    return is_dir("$path/releases")
        && (is_dir("$path/storage") || is_dir("$path/shared/storage"))
        && (file_exists("$path/.env") || file_exists("$path/shared/.env"));
}

function is_laravel_project(string $path): bool
{
    return is_existing_zero_downtime_project($path) || file_exists("$path/artisan") || file_exists("$path/composer.json");
}

function directory_is_not_empty(string $path): bool
{
    if (is_file($path)) {
        return true;
    }

    if (! is_dir($path)) {
        return false;
    }

    // A deploy key left behind by an earlier "lit init" does not count
    $deployKeyName = basename(deploy_key_path($path));

    return array_diff(scandir($path), ['.', '..', $deployKeyName, "$deployKeyName.pub"]) !== [];
}

function create_env_from_git_env_example(string $projectPath, string $sourceUrl): bool
{
    $clonePath = "$projectPath/env-example-clone";

    delete_directory($clonePath);

    // "--filter=blob:none" skips downloading file contents, "--no-cone" then
    // fetches only the ".env.example" blob instead of every file in the root
    [$cloneStatusCode] = run_command_and_capture(git_command(['clone', '--quiet', '--no-checkout', '--depth', '1', '--filter=blob:none', $sourceUrl, $clonePath]));

    if ($cloneStatusCode === 0) {
        run_command_and_capture(['git', 'sparse-checkout', 'set', '--no-cone', '.env.example'], $clonePath);
        run_command_and_capture(['git', 'checkout'], $clonePath);
    }

    $createdEnvFile = file_exists("$clonePath/.env.example");

    if ($createdEnvFile) {
        $envContents = file_get_contents("$clonePath/.env.example");

        // Generate an APP_KEY the same way "php artisan key:generate" does
        $appKey = 'base64:'.base64_encode(random_bytes(32));

        $replacedAppKeyCount = 0;

        $envContents = preg_replace('/^APP_KEY=.*/m', "APP_KEY=$appKey", $envContents, count: $replacedAppKeyCount);

        file_put_contents("$projectPath/.env", $envContents);

        out("Created \".env\" from the \".env.example\" in the repository\n");

        if ($replacedAppKeyCount > 0) {
            out("Application key (APP_KEY) set successfully.\n");
        }
    }

    delete_directory($clonePath);

    return $createdEnvFile;
}

// Runs when reading the repository failed. Sets up a deploy key when that can fix
// the error. Returns true when init should read the repository again
function offer_deploy_key(string $projectPath, string $sourceUrl, string $customProjectName, string $gitError): bool
{
    $githubRepository = github_repository($sourceUrl);

    // A deploy key only works over ssh
    if (! is_ssh_git_url($sourceUrl)) {
        if ($githubRepository !== '') {
            out("\n");
            out("Tip: with the SSH URL, Lit can set up a deploy key for you:\n");
            out(rtrim("  lit init git@github.com:$githubRepository.git $customProjectName")."\n");
        }

        return false;
    }

    if (! is_git_access_error($gitError)) {
        return false;
    }

    $prettyDeployKeyPath = basename($projectPath).'/'.basename(deploy_key_path($projectPath));

    out("\n");

    if (is_file(deploy_key_path($projectPath))) {
        out("The deploy key \"$prettyDeployKeyPath\" has no access to the repository\n");
    } else {
        out('Generate a deploy key'.($githubRepository !== '' ? ' for GitHub' : '')."?\n");

        if (yes_no_menu() === 'n') {
            return false;
        }

        out("\n");

        // The project directory is normally created after reading the repository
        if (! is_dir($projectPath)) {
            mkdir($projectPath, 0777, true);
        }

        generate_deploy_key($projectPath);
    }

    out("\n");

    print_deploy_key_instructions($projectPath, $sourceUrl);

    out("\n");

    if (! wait_for_enter('Press enter to try again once you have added the key')) {
        $initCommand = rtrim("lit init $sourceUrl $customProjectName");

        out("\n");
        out("Run \"$initCommand\" again once you have added the key\n");

        lit_exit(130);
    }

    out("\n");

    return true;
}

$initInCurrentDirectory = false;
$initInNonZeroDowntimeProject = false;
$projectName = '';
$projectPath = '';

// When running "lit init <url>" without specifying a project name, check if the current directory
// is an existing Laravel project, if yes, init in the current directory.
if ($customProjectName === '.' || ($customProjectName === '' && is_laravel_project($projectBasePath))) {
    $projectPath = $projectBasePath;
    $projectName = basename($projectBasePath);
    $initInCurrentDirectory = true;

    if (! is_existing_zero_downtime_project($projectBasePath)) {
        $initInNonZeroDowntimeProject = true;
    }
}

if (! $initInCurrentDirectory) {
    if ($customProjectName !== '') {
        $projectNameIsValid = $customProjectName !== '.'
            && $customProjectName !== '..'
            && ! str_contains($customProjectName, '/')
            && ! preg_match('/[\x01-\x1f\x7f]/', $customProjectName);

        if (! $projectNameIsValid) {
            out("Invalid project name \"$customProjectName\"\n");

            lit_exit(1);
        }

        $projectName = $customProjectName;
    } elseif ($sourceType === 'git') {
        $projectName = basename($sourceUrl, '.git');
    } else {
        $projectName = preg_replace('/(.*)\.tar.*/', '$1', basename($sourceUrl));
    }

    $projectPath = "$projectBasePath/$projectName";
}

// Git commands use the deploy key of the project (if it has one)
$GLOBALS['deploy_key_path'] = deploy_key_path($projectPath);

// Check if the directory already exists and is not empty
if (directory_is_not_empty($projectPath)) {
    if (is_laravel_project($projectPath)) {
        if (! is_existing_zero_downtime_project($projectPath)) {
            $initInNonZeroDowntimeProject = true;
        }
    } else {
        out("Directory \"$projectName\" already exists and is not a Laravel project\n");

        lit_exit(1);
    }
}

$defaultBranch = '';

if ($sourceType === 'git') {
    // Reading fails without access, a deploy key can fix that, then try again
    while (true) {
        out("Reading \"$sourceUrl\"... ");

        [$lsRemoteStatusCode, $defaultBranchInfo, $lsRemoteError] = run_command_and_capture_stdout(git_command(['ls-remote', '--symref', $sourceUrl, 'HEAD']));

        if ($lsRemoteStatusCode === 0) {
            break;
        }

        if (! offer_deploy_key($projectPath, $sourceUrl, $customProjectName, $lsRemoteError)) {
            lit_exit($lsRemoteStatusCode);
        }
    }

    foreach (explode("\n", $defaultBranchInfo) as $lsRemoteLine) {
        if (str_starts_with($lsRemoteLine, 'ref: refs/heads/')) {
            $defaultBranch = explode("\t", substr($lsRemoteLine, strlen('ref: refs/heads/')))[0];

            break;
        }
    }

    out("Done!\n");
    out("\n");
}

if (! is_dir($projectPath)) {
    mkdir($projectPath, 0777, true);
}

$switchedLitSourceType = false;

// The project might still be on Lit v1, migrate it first
if (! file_exists("$projectPath/lit.json") && (file_exists("$projectPath/git-repository-url") || file_exists("$projectPath/bundle-url"))) {
    require_once "$litBasePath/scripts/migrate-state-from-v1-to-v2.php";

    migrate_state_from_v1_to_v2($projectPath);
}

$oldLitState = read_lit_state($projectPath);

if ($sourceType === 'git') {
    if (($oldLitState['bundle_url'] ?? '') !== '') {
        out("Changing from bundle URL: {$oldLitState['bundle_url']}\n");

        $switchedLitSourceType = true;
    } elseif (($oldLitState['git_repository_url'] ?? '') !== '') {
        out("Changing from git repository URL: {$oldLitState['git_repository_url']}\n");
    }

    write_lit_state($projectPath, [
        'git_repository_url' => $sourceUrl,
        'git_ref' => $defaultBranch,
        'git_ref_type' => 'branch',
        'git_commit_sha' => 'not deployed yet',
        'git_release_caching_enabled' => ($oldLitState['git_release_caching_enabled'] ?? false) === true,
    ]);

    out("Current branch set to \"$defaultBranch\"\n");
} elseif ($sourceType === 'bundle') {
    if (($oldLitState['git_repository_url'] ?? '') !== '') {
        $oldRefType = $oldLitState['git_ref_type'] ?? 'branch';
        $oldRef = $oldLitState['git_ref'] ?? '';

        $oldRefLabel = $oldRef === '' ? 'branch: no branch' : "$oldRefType: $oldRef";

        out("Changing from git URL: {$oldLitState['git_repository_url']} ($oldRefLabel)\n");

        $switchedLitSourceType = true;
    } elseif (($oldLitState['bundle_url'] ?? '') !== '') {
        out("Changing from bundle URL: {$oldLitState['bundle_url']}\n");
    }

    write_lit_state($projectPath, [
        'bundle_url' => $sourceUrl,
        'bundle_hash' => 'not deployed yet',
    ]);

    out("Bundle URL set to \"$sourceUrl\"\n");
}

out("\n");

if (! is_dir("$projectPath/hooks")) {
    mkdir("$projectPath/hooks", 0777, true);
}

$createdHooks = [];

if (! file_exists("$projectPath/hooks/before-release.sh")) {
    copy("$litBasePath/stubs/hooks-for-$sourceType/before-release.sh.stub", "$projectPath/hooks/before-release.sh");

    $createdHooks[] = 'hooks/before-release.sh';
}

if (! file_exists("$projectPath/hooks/after-release.sh")) {
    copy("$litBasePath/stubs/hooks-for-$sourceType/after-release.sh.stub", "$projectPath/hooks/after-release.sh");

    $createdHooks[] = 'hooks/after-release.sh';
}

if (! file_exists("$projectPath/hooks/on-failure.sh")) {
    copy("$litBasePath/stubs/on-failure.sh.stub", "$projectPath/hooks/on-failure.sh");

    $createdHooks[] = 'hooks/on-failure.sh';
}

if (! is_dir("$projectPath/storage") && ! is_dir("$projectPath/shared/storage")) {
    foreach (['app/public', 'app/private', 'framework/cache/data', 'framework/sessions', 'framework/testing', 'framework/views', 'logs'] as $storageSubdirectory) {
        mkdir("$projectPath/storage/$storageSubdirectory", 0777, true);
    }
}

$createdEnvFromEnvExample = false;

if (file_exists("$projectPath/.env")) {
    $envFilePath = "$projectPath/.env";
} elseif (file_exists("$projectPath/shared/.env")) {
    $envFilePath = "$projectPath/shared/.env";
} else {
    $envFilePath = "$projectPath/.env";

    if ($sourceType === 'git') {
        $createdEnvFromEnvExample = create_env_from_git_env_example($projectPath, $sourceUrl);
    }

    if (! file_exists($envFilePath)) {
        touch($envFilePath);
    }
}

if (! is_dir("$projectPath/releases")) {
    mkdir("$projectPath/releases", 0777, true);
}

out("Finished initializing \"$projectName\"\n");

// A ".env" copied from the ".env.example" only holds defaults, so it still needs filling in
$envFileNeedsFillingIn = filesize($envFilePath) === 0 || $createdEnvFromEnvExample;

$hasNextSteps = ! $initInCurrentDirectory || $envFileNeedsFillingIn || $createdHooks || $switchedLitSourceType || $initInNonZeroDowntimeProject;

if ($hasNextSteps) {
    out("\n");
    out("Next steps:\n");

    if (! $initInCurrentDirectory) {
        out("- cd \"$projectName\"\n");
    }

    if ($envFileNeedsFillingIn) {
        out("- Fill in the \".env\" file\n");
    }

    if ($switchedLitSourceType) {
        out("- Review these hooks:\n");
        out("  - \"hooks/before-release.sh\"\n");
        out("  - \"hooks/after-release.sh\"\n");
        out("  - \"hooks/on-failure.sh\"\n");
    } elseif ($createdHooks) {
        out("- Review these newly created hooks:\n");

        foreach ($createdHooks as $createdHook) {
            out("  - \"$createdHook\"\n");
        }
    }

    if ($sourceType === 'git') {
        out("\n");
        out("After that, either:\n");
        out("- Run \"lit deploy\" to deploy the current branch ($defaultBranch)\n");
        out("- Run \"lit checkout <branch|tag|commit>\" to deploy something else\n");
    } elseif ($sourceType === 'bundle') {
        out("\n");
        out("After that, run \"lit deploy\" to download and deploy the bundle\n");
    }

    if ($initInNonZeroDowntimeProject) {
        out("\n");
        out("After you have deployed with Lit:\n");
        out("- Update your cron and queue workers to point at \"/current/artisan\" instead of \"/artisan\"\n");
        out("- Update your nginx to point at \"/current/public/index.php\" instead of \"/public/index.php\"\n");
        out("\n");
        out("(Optional) Delete the original Laravel project files, keeping only:\n");

        if ($sourceType === 'git') {
            out("- Directories: current/, hooks/, logs/, releases/, storage/\n");
        } else {
            out("- Directories: current/, hooks/, releases/, storage/\n");
        }

        $filesToKeep = '.env, lit.json';

        if (is_file(deploy_key_path($projectPath))) {
            $filesToKeep .= ', deploy-key, deploy-key.pub';
        }

        out("- Files: $filesToKeep\n");

        if (glob("$projectPath/database/*.sqlite")) {
            out("\n");
            out("Warning:\n");
            out("The SQLite files in your \"database/\" directory must be moved.\n");
            out("Move them to the root of your project and set this in your \".env\":\n");
            out("DB_DATABASE=\"$projectPath/database.sqlite\"\n");
        }
    }
} else {
    out("\n");

    if ($sourceType === 'git') {
        out("Run \"lit deploy\" to deploy the current branch ($defaultBranch)\n");
        out("Run \"lit checkout <branch|tag|commit>\" to deploy something else\n");
    } elseif ($sourceType === 'bundle') {
        out("Run \"lit deploy\" to download and deploy the bundle\n");
    }
}

out("\n");
