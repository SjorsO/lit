#!/bin/bash

set -e

lit_base_path="$1"
base_path="$2"
source_url="$3"

source "$lit_base_path/scripts/helpers.sh"

if [ -z "$source_url" ]; then
    printf 'usage: lit clone <url>\n'
    printf '\n'
    printf 'Examples:\n'
    printf '  lit clone https://github.com/user/repo.git\n'
    printf '  lit clone https://example.com/releases/app.tar.gz\n'

    exit 1
fi

if [[ "$source_url" == *.git ]] || [[ "$source_url" == git@* ]]; then
    source_type="git"
    project_name=$(basename "$source_url" .git)
else
    source_type="bundle"
    project_name=$(basename "$source_url" | sed -E 's/\.(tar\.(gz|zst)|tgz)$//')
fi

project_path="$base_path/$project_name"

if [ "$(ls -A "$project_path" 2>/dev/null)" ]; then
    printf 'Directory "%s" already exists and is not empty.\n' "$project_name"

    exit 1
fi

mkdir -p "$project_path"
mkdir -p "$project_path/lit"

echo "$source_type" > "$project_path/lit/source-type"

if [ "$source_type" = "git" ]; then
    echo "$source_url" > "$project_path/lit/git-repository-url"

    printf 'Reading "%s"... ' "$source_url"

    default_branch_info=$(git ls-remote --symref "$source_url" HEAD)

    default_branch=$(echo "$default_branch_info" | grep "ref: refs/heads/" | sed 's/ref: refs\/heads\///' | cut -f1)

    echo "$default_branch" > "$project_path/lit/current-branch"
    echo "not deployed yet" > "$project_path/lit/current-commit"

    printf 'Done!\n'
    printf '\n'
    printf 'Current branch set to "%s"\n' "$default_branch"
elif [ "$source_type" = "bundle" ]; then
    echo "$source_url" > "$project_path/lit/bundle-url"

    echo "not deployed yet" > "$project_path/lit/current-bundle-hash"

    printf 'Bundle URL set to "%s"\n' "$source_url"
fi

printf '\n'

mkdir -p "$project_path/hooks"
cp "$lit_base_path/stubs/hooks-for-$source_type/before-activation.sh.stub" "$project_path/hooks/before-activation.sh"
cp "$lit_base_path/stubs/hooks-for-$source_type/after-activation.sh.stub" "$project_path/hooks/after-activation.sh"
cp "$lit_base_path/stubs/on-failure.sh.stub" "$project_path/hooks/on-failure.sh"

touch "$project_path/.env"

mkdir -p "$project_path/releases"

printf 'Finished cloning into "%s"\n' "$project_name"
printf '\n'
printf 'Next steps:\n'
printf -- '- cd "%s"\n' "$project_name"
printf -- '- Fill in the ".env" file\n'
printf -- '- Review these hooks:\n'
printf '  - "hooks/before-activation.sh"\n'
printf '  - "hooks/after-activation.sh"\n'
printf '  - "hooks/on-failure.sh"\n'
printf '\n'

if [ "$source_type" = "git" ]; then
    printf 'After that, either:\n'
    printf -- '- Run "lit pull" to deploy the current branch (%s)\n' "$default_branch"
    printf -- '- Run "lit checkout <branch>" to deploy a different branch\n'
elif [ "$source_type" = "bundle" ]; then
    printf 'After that, run "lit pull" to download and deploy the bundle\n'
fi

printf '\n'
