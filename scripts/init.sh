#!/bin/bash

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

if [ -n "$custom_project_name" ]; then
    if ! [[ "$custom_project_name" =~ ^[a-zA-Z0-9._-]+$ ]]; then
        printf 'Project name "%s" contains invalid characters. Only a-z, 0-9, _, - and . are allowed.\n' "$custom_project_name"

        exit 1
    fi

    project_name="$custom_project_name"
elif [ "$source_type" = "git" ]; then
    project_name=$(basename "$source_url" .git)
else
    project_name=$(basename "$source_url" | sed -E 's/\.(tar\.(gz|zst)|tgz)$//')
fi

project_path="$base_path/$project_name"

if [ "$(ls -A "$project_path" 2>/dev/null)" ]; then
    printf 'Directory "%s" already exists and is not empty.\n' "$project_name"

    exit 1
fi

if [ "$source_type" = "git" ]; then
    printf 'Reading "%s"... ' "$source_url"

    default_branch_info=$(git ls-remote --symref "$source_url" HEAD)

    default_branch=$(echo "$default_branch_info" | grep "ref: refs/heads/" | sed 's/ref: refs\/heads\///' | cut -f1)

    printf 'Done!\n'
    printf '\n'
fi

mkdir -p "$project_path"
mkdir -p "$project_path/lit"

echo "$source_type" > "$project_path/lit/source-type"

if [ "$source_type" = "git" ]; then
    echo "$source_url" > "$project_path/lit/git-repository-url"
    echo "$default_branch" > "$project_path/lit/current-branch"
    echo "not deployed yet" > "$project_path/lit/current-commit"

    printf 'Current branch set to "%s"\n' "$default_branch"
elif [ "$source_type" = "bundle" ]; then
    echo "$source_url" > "$project_path/lit/bundle-url"

    echo "not deployed yet" > "$project_path/lit/current-bundle-hash"

    printf 'Bundle URL set to "%s"\n' "$source_url"
fi

printf '\n'

mkdir -p "$project_path/hooks"
cp "$lit_base_path/stubs/hooks-for-$source_type/before-release.sh.stub" "$project_path/hooks/before-release.sh"
cp "$lit_base_path/stubs/hooks-for-$source_type/after-release.sh.stub" "$project_path/hooks/after-release.sh"
cp "$lit_base_path/stubs/on-failure.sh.stub" "$project_path/hooks/on-failure.sh"

touch "$project_path/.env"

mkdir -p "$project_path/releases"

printf 'Finished initializing "%s"\n' "$project_name"
printf '\n'
printf 'Next steps:\n'
printf -- '- cd "%s"\n' "$project_name"
printf -- '- Fill in the ".env" file\n'
printf -- '- Review these hooks:\n'
printf '  - "hooks/before-release.sh"\n'
printf '  - "hooks/after-release.sh"\n'
printf '  - "hooks/on-failure.sh"\n'
printf '\n'

if [ "$source_type" = "git" ]; then
    printf 'After that, either:\n'
    printf -- '- Run "lit deploy" to deploy the current branch (%s)\n' "$default_branch"
    printf -- '- Run "lit checkout <branch>" to deploy a different branch\n'
elif [ "$source_type" = "bundle" ]; then
    printf 'After that, run "lit deploy" to download and deploy the bundle\n'
fi

printf '\n'
