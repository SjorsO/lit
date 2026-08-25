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

    return is_dir($path) && count(scandir($path)) > 2;
}

function create_env_from_git_env_example(string $projectPath, string $sourceUrl): bool
{
    $clonePath = "$projectPath/env-example-clone";

    delete_directory($clonePath);

    // "--filter=blob:none" skips downloading file contents, "--no-cone" then
    // fetches only the ".env.example" blob instead of every file in the root
    [$cloneStatusCode] = run_command_and_capture(['git', 'clone', '--quiet', '--no-checkout', '--depth', '1', '--filter=blob:none', $sourceUrl, $clonePath]);

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
    out("Reading \"$sourceUrl\"... ");

    [$lsRemoteStatusCode, $defaultBranchInfo] = run_command_and_capture_stdout(['git', 'ls-remote', '--symref', $sourceUrl, 'HEAD']);

    if ($lsRemoteStatusCode !== 0) {
        lit_exit($lsRemoteStatusCode);
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

if ($sourceType === 'git') {
    if (file_exists("$projectPath/bundle-url") && filesize("$projectPath/bundle-url") > 0) {
        $oldBundleUrl = rtrim(file_get_contents("$projectPath/bundle-url"), "\n");

        out("Changing from bundle URL: $oldBundleUrl\n");

        $switchedLitSourceType = true;
    } elseif (file_exists("$projectPath/git-repository-url") && filesize("$projectPath/git-repository-url") > 0) {
        $oldGitUrl = rtrim(file_get_contents("$projectPath/git-repository-url"), "\n");

        out("Changing from git repository URL: $oldGitUrl\n");
    }

    delete_file("$projectPath/bundle-url");
    delete_file("$projectPath/bundle-hash");

    file_put_contents("$projectPath/git-repository-url", "$sourceUrl\n");
    file_put_contents("$projectPath/git-branch", "$defaultBranch\n");
    file_put_contents("$projectPath/git-commit", "not deployed yet\n");

    out("Current branch set to \"$defaultBranch\"\n");
} elseif ($sourceType === 'bundle') {
    if (file_exists("$projectPath/git-repository-url") && filesize("$projectPath/git-repository-url") > 0) {
        $oldGitUrl = rtrim(file_get_contents("$projectPath/git-repository-url"), "\n");
        $oldBranch = file_exists("$projectPath/git-branch") ? rtrim(file_get_contents("$projectPath/git-branch"), "\n") : '';

        if ($oldBranch === '') {
            $oldBranch = 'no branch';
        }

        out("Changing from git URL: $oldGitUrl (branch: $oldBranch)\n");

        $switchedLitSourceType = true;
    } elseif (file_exists("$projectPath/bundle-url") && filesize("$projectPath/bundle-url") > 0) {
        $oldBundleUrl = rtrim(file_get_contents("$projectPath/bundle-url"), "\n");

        out("Changing from bundle URL: $oldBundleUrl\n");
    }

    delete_file("$projectPath/git-repository-url");
    delete_file("$projectPath/git-branch");
    delete_file("$projectPath/git-commit");
    delete_file("$projectPath/git-release-caching-enabled");

    file_put_contents("$projectPath/bundle-url", "$sourceUrl\n");
    file_put_contents("$projectPath/bundle-hash", "not deployed yet\n");

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
        out("- Run \"lit checkout <branch>\" to deploy a different branch\n");
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
            out("- Files: .env, git-repository-url, git-branch, git-commit\n");
        } else {
            out("- Directories: current/, hooks/, releases/, storage/\n");
            out("- Files: .env, bundle-url, bundle-hash\n");
        }

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
        out("Run \"lit checkout <branch>\" to deploy a different branch\n");
    } elseif ($sourceType === 'bundle') {
        out("Run \"lit deploy\" to download and deploy the bundle\n");
    }
}

out("\n");
