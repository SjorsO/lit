#!/bin/bash

set -e

if [ "$(ls -A . 2>/dev/null)" ]; then
    echo "Current directory is not empty."
    echo ""
    echo "Please try again in an empty directory."
    echo ""
    exit 1
fi

lit_base_path="$1"
git_repository_url="$2"
project_base_path="$(pwd)"

source "$lit_base_path/scripts/helpers.sh"

if [ -z "$git_repository_url" ]; then
    echo "usage: lit init <git_repository_url>"

    exit 1
fi

mkdir -p "$project_base_path/lit"

echo "$git_repository_url" > "$project_base_path/lit/git-repository-url"

printf "Reading \"$git_repository_url\"... "

default_branch_info=$(git ls-remote --symref "$git_repository_url" HEAD)

default_branch=$(echo "$default_branch_info" | grep "ref: refs/heads/" | sed 's/ref: refs\/heads\///' | cut -f1)
default_commit=$(echo "$default_branch_info" | grep -v "ref: refs/heads/" | cut -f1)

echo "$default_branch" > "$project_base_path/lit/current-branch"
echo "$default_commit" > "$project_base_path/lit/current-commit"

echo "Done!"
echo ""
echo "Current branch set to \"$default_branch\""
echo ""

mkdir -p "$project_base_path/lit/hooks"
cp "$lit_base_path/stubs/after-pulling-hook.sh.stub" "$project_base_path/lit/hooks/after-pulling.sh"
cp "$lit_base_path/stubs/before-activitation-hook.sh.stub" "$project_base_path/lit/hooks/before-activitation.sh"
cp "$lit_base_path/stubs/after-activation-hook.sh.stub" "$project_base_path/lit/hooks/after-activation.sh"

touch "$project_base_path/.env"

mkdir -p "$project_base_path/releases"

echo "Finished initializing Lit in this directory"
echo ""
echo "Next steps:"
echo "- Fill in the \".env\" file"
echo "- Review these hooks and change them if necessary:"
echo "  - \"lit/hooks/after-pulling.sh\""
echo "  - \"lit/hooks/before-activitation.sh\""
echo "  - \"lit/hooks/after-activation.sh\""
echo ""
echo "After that, either:"
echo "- Run \"lit pull\" to deploy the current branch ($default_branch)"
echo "- Run \"lit checkout <branch>\" to deploy a different branch"
echo ""
