#!/bin/bash

set -e

lit_base_path="$1"
base_path="$2"
git_repository_url="$3"

source "$lit_base_path/scripts/helpers.sh"

if [ -z "$git_repository_url" ]; then
    echo "usage: lit clone <git_repository_url>"

    exit 1
fi

project_name=$(basename "$git_repository_url" .git)
project_path="$base_path/$project_name"

if [ "$(ls -A "$project_path" 2>/dev/null)" ]; then
    echo "Directory \"$project_name\" already exists and is not empty."

    exit 1
fi

mkdir -p "$project_path"

mkdir -p "$project_path/lit"

echo "$git_repository_url" > "$project_path/lit/git-repository-url"

printf 'Reading "%s"... ' "$git_repository_url"

default_branch_info=$(git ls-remote --symref "$git_repository_url" HEAD)

default_branch=$(echo "$default_branch_info" | grep "ref: refs/heads/" | sed 's/ref: refs\/heads\///' | cut -f1)

echo "$default_branch" > "$project_path/lit/current-branch"
echo "not deployed yet" > "$project_path/lit/current-commit"

echo "Done!"
echo ""
echo "Current branch set to \"$default_branch\""
echo ""

mkdir -p "$project_path/lit/hooks"
cp "$lit_base_path/stubs/before-activation.sh.stub" "$project_path/lit/hooks/before-activation.sh"
cp "$lit_base_path/stubs/after-activation.sh.stub" "$project_path/lit/hooks/after-activation.sh"

touch "$project_path/.env"

mkdir -p "$project_path/releases"

echo "Finished cloning into \"$project_name\""
echo ""
echo "Next steps:"
echo "- cd \"$project_name\""
echo "- Fill in the \".env\" file"
echo "- Review these hooks and change them if necessary:"
echo "  - \"lit/hooks/before-activation.sh\""
echo "  - \"lit/hooks/after-activation.sh\""
echo ""
echo "After that, either:"
echo "- Run \"lit pull\" to deploy the current branch ($default_branch)"
echo "- Run \"lit checkout <branch>\" to deploy a different branch"
echo ""
