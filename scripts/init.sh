#!/bin/bash
#
# When you run `lit init <url> [project name]`:
# - If a [project name] is passed in, that directory is used.
# - If [project name] is empty or ".", the current directory is used if it is a Laravel project.
# - If the current directory is not a Laravel project, and no [project name] is specified, Lit will
#   use the project name from the <url>.
# - If the directory that Lit will use is not empty, and not a Laravel project, then Lit aborts.
#
# Lit can init in:
# - An empty directory
# - A directory containing a Lit project (to update the git/bundle url)
# - A directory containing a zero downtime structure Laravel project (containing a ".env" file and
#   "storage" directory, and "releases" directory).
# - A directory containing a non-zero downtime structure Laravel project (containing the "artisan"
#   and "composer.json" files, but missing the "releases" directory).

set -e

lit_base_path="$1"
base_path="$2"
source_url="$3"
custom_project_name="$4"

source "$lit_base_path/scripts/helpers.sh"

if [ -z "$source_url" ] || [ -n "$5" ]; then
    printf 'usage: lit init <url> [project-name]\n'
    printf '\n'
    printf 'Examples:\n'
    printf '  lit init https://github.com/user/repo.git\n'
    printf '  lit init https://github.com/user/repo.git my-project\n'
    printf '  lit init https://example.com/releases/app.tar.gz\n'

    exit 1
fi

if [[ "$source_url" == *.git ]] || [[ "$source_url" == git@* ]]; then
    source_type="git"
else
    source_type="bundle"
fi

is_existing_zero_downtime_project() {
    local path="$1"

    [ -d "$path/releases" ] && { { [ -d "$path/storage" ] || [ -d "$path/shared/storage" ]; } && { [ -f "$path/.env" ] || [ -f "$path/shared/.env" ]; }; }
}

is_laravel_project() {
    local path="$1"

    is_existing_zero_downtime_project "$path" || [ -f "$path/artisan" ] || [ -f "$path/composer.json" ]
}

init_in_current_directory=false
init_in_non_zero_downtime_project=false

# When running "lit init <url>" without specifying a project name, check if the current directory
# is an existing Laravel project, if yes, init in the current directory.
if [ "$custom_project_name" = "." ] || { [ -z "$custom_project_name" ] && is_laravel_project "$base_path"; }; then
    project_path="$base_path"
    project_name="$(basename "$base_path")"
    init_in_current_directory=true

    is_existing_zero_downtime_project "$base_path" || init_in_non_zero_downtime_project=true
fi

if [ "$init_in_current_directory" = false ]; then
    if [ -n "$custom_project_name" ]; then
        if ! [[ "$custom_project_name" != . && "$custom_project_name" != .. && "$custom_project_name" != */* && "$custom_project_name" != *[$'\001'-$'\037'$'\177']* ]]; then
            printf 'Invalid project name "%s"\n' "$custom_project_name"

            exit 1
        fi

        project_name="$custom_project_name"
    elif [ "$source_type" = "git" ]; then
        project_name=$(basename "$source_url" .git)
    else
        project_name=$(basename "$source_url" | sed 's/\(.*\)\.tar.*/\1/')
    fi

    project_path="$base_path/$project_name"
fi

# Check if the directory already exists and is not empty
if [ "$(ls -A "$project_path" 2>/dev/null)" ]; then
    if is_laravel_project "$project_path"; then
        is_existing_zero_downtime_project "$project_path" || init_in_non_zero_downtime_project=true
    else
        printf 'Directory "%s" already exists and is not a Laravel project\n' "$project_name"

        exit 1
    fi
fi


if [ "$source_type" = "git" ]; then
    printf 'Reading "%s"... ' "$source_url"

    default_branch_info=$(git ls-remote --symref "$source_url" HEAD)

    default_branch=$(echo "$default_branch_info" | grep "ref: refs/heads/" | sed 's/ref: refs\/heads\///' | cut -f1)

    printf 'Done!\n'
    printf '\n'
fi

mkdir -p "$project_path"

switched_lit_source_type=false

if [ "$source_type" = "git" ]; then
    if [ -s "$project_path/bundle-url" ]; then
        printf 'Changing from bundle URL: %s\n' "$(cat "$project_path/bundle-url")"

        switched_lit_source_type=true
    elif [ -s "$project_path/git-repository-url" ]; then
        printf 'Changing from git repository URL: %s\n' "$(cat "$project_path/git-repository-url")"
    fi

    rm -f "$project_path/bundle-url"
    rm -f "$project_path/bundle-hash"

    echo "$source_url" > "$project_path/git-repository-url"
    echo "$default_branch" > "$project_path/git-branch"
    echo "not deployed yet" > "$project_path/git-commit"

    printf 'Current branch set to "%s"\n' "$default_branch"
elif [ "$source_type" = "bundle" ]; then
    if [ -s "$project_path/git-repository-url" ]; then
        old_branch=$(cat "$project_path/git-branch" 2>/dev/null || true)

        printf 'Changing from git URL: %s (branch: %s)\n' "$(cat "$project_path/git-repository-url")" "${old_branch:-no branch}"

        switched_lit_source_type=true
    elif [ -s "$project_path/bundle-url" ]; then
        printf 'Changing from bundle URL: %s\n' "$(cat "$project_path/bundle-url")"
    fi

    rm -f "$project_path/git-repository-url"
    rm -f "$project_path/git-branch"
    rm -f "$project_path/git-commit"
    rm -f "$project_path/git-release-caching-enabled"

    echo "$source_url" > "$project_path/bundle-url"
    echo "not deployed yet" > "$project_path/bundle-hash"

    printf 'Bundle URL set to "%s"\n' "$source_url"
fi

printf '\n'

mkdir -p "$project_path/hooks"

created_hooks=()

if [ ! -f "$project_path/hooks/before-release.sh" ]; then
    cp "$lit_base_path/stubs/hooks-for-$source_type/before-release.sh.stub" "$project_path/hooks/before-release.sh"
    created_hooks+=("hooks/before-release.sh")
fi

if [ ! -f "$project_path/hooks/after-release.sh" ]; then
    cp "$lit_base_path/stubs/hooks-for-$source_type/after-release.sh.stub" "$project_path/hooks/after-release.sh"
    created_hooks+=("hooks/after-release.sh")
fi

if [ ! -f "$project_path/hooks/on-failure.sh" ]; then
    cp "$lit_base_path/stubs/on-failure.sh.stub" "$project_path/hooks/on-failure.sh"
    created_hooks+=("hooks/on-failure.sh")
fi

if [ ! -d "$project_path/storage" ] && [ ! -d "$project_path/shared/storage" ]; then
    mkdir -p "$project_path/storage/"{app/public,app/private,framework/{cache/data,sessions,testing,views},logs}
fi

if [ -f "$project_path/.env" ]; then
    env_file_path="$project_path/.env"
elif [ -f "$project_path/shared/.env" ]; then
    env_file_path="$project_path/shared/.env"
else
    touch "$project_path/.env"

    env_file_path="$project_path/.env"
fi

mkdir -p "$project_path/releases"

printf 'Finished initializing "%s"\n' "$project_name"

has_next_steps=$([ "$init_in_current_directory" = false ] || [ ! -s "$env_file_path" ] || [ ${#created_hooks[@]} -gt 0 ] || [ "$switched_lit_source_type" = true ] || [ "$init_in_non_zero_downtime_project" = true ] && echo true || echo false)

if [ "$has_next_steps" = true ]; then
    printf '\n'
    printf 'Next steps:\n'

    if [ "$init_in_current_directory" = false ]; then
        printf -- '- cd "%s"\n' "$project_name"
    fi

    if [ ! -s "$env_file_path" ]; then
        printf -- '- Fill in the ".env" file\n'
    fi

    if [ "$switched_lit_source_type" = true ]; then
        printf -- '- Review these hooks:\n'
        printf '  - "hooks/before-release.sh"\n'
        printf '  - "hooks/after-release.sh"\n'
        printf '  - "hooks/on-failure.sh"\n'
    elif [ ${#created_hooks[@]} -gt 0 ]; then
        printf -- '- Review these newly created hooks:\n'
        for hook in "${created_hooks[@]}"; do
            printf '  - "%s"\n' "$hook"
        done
    fi

    if [ "$source_type" = "git" ]; then
        printf '\n'
        printf 'After that, either:\n'
        printf -- '- Run "lit deploy" to deploy the current branch (%s)\n' "$default_branch"
        printf -- '- Run "lit checkout <branch>" to deploy a different branch\n'
    elif [ "$source_type" = "bundle" ]; then
        printf '\n'
        printf 'After that, run "lit deploy" to download and deploy the bundle\n'
    fi

    if [ "$init_in_non_zero_downtime_project" = true ]; then
        printf '\n'
        printf -- 'After you have deployed with Lit:\n'
        printf -- '- Update your cron and queue workers to point at "/current/artisan" instead of "/artisan"\n'
        printf -- '- Update your nginx to point at "/current/public/index.php" instead of "/public/index.php"\n'
        printf '\n'
        printf -- '(Optional) Delete the original Laravel project files, keeping only:\n'
        if [ "$source_type" = "git" ]; then
            printf -- '- Directories: current/, hooks/, logs/, releases/, storage/\n'
            printf -- '- Files: .env, git-repository-url, git-branch, git-commit\n'
        else
            printf -- '- Directories: current/, hooks/, releases/, storage/\n'
            printf -- '- Files: .env, bundle-url, bundle-hash\n'
        fi

        if ls "$project_path/database"/*.sqlite 1> /dev/null 2>&1; then
            printf '\n'
            printf -- 'Warning:\n'
            printf -- 'The SQLite files in your "database/" directory must be moved.\n'
            printf -- 'Move them to the root of your project and set this in your ".env":\n'
            printf -- 'DB_DATABASE="%s/database.sqlite"\n' "$project_path"
        fi
    fi
else
    printf '\n'

    if [ "$source_type" = "git" ]; then
        printf -- 'Run "lit deploy" to deploy the current branch (%s)\n' "$default_branch"
        printf -- 'Run "lit checkout <branch>" to deploy a different branch\n'
    elif [ "$source_type" = "bundle" ]; then
        printf 'Run "lit deploy" to download and deploy the bundle\n'
    fi
fi

printf '\n'
