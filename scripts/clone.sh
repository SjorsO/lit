#!/bin/bash

set -e

lit_base_path="$1"
base_path="$2"
source_url="$3"

source "$lit_base_path/scripts/helpers.sh"

if [ -z "$source_url" ]; then
    echo "usage: lit clone <url>"
    echo ""
    echo "Examples:"
    echo "  lit clone https://github.com/user/repo.git"
    echo "  lit clone https://example.com/releases/app.tar.gz"

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
    echo "Directory \"$project_name\" already exists and is not empty."

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

    echo "Done!"
    echo ""
    echo "Current branch set to \"$default_branch\""
elif [ "$source_type" = "bundle" ]; then
    echo "$source_url" > "$project_path/lit/bundle-url"

    echo "not deployed yet" > "$project_path/lit/current-bundle-hash"

    echo "Bundle URL set to \"$source_url\""
fi

echo ""

mkdir -p "$project_path/hooks"
cp "$lit_base_path/stubs/hooks-for-$source_type/before-activation.sh.stub" "$project_path/hooks/before-activation.sh"
cp "$lit_base_path/stubs/hooks-for-$source_type/after-activation.sh.stub" "$project_path/hooks/after-activation.sh"

touch "$project_path/.env"

mkdir -p "$project_path/releases"

echo "Finished cloning into \"$project_name\""
echo ""
echo "Next steps:"
echo "- cd \"$project_name\""
echo "- Fill in the \".env\" file"
echo "- Review these hooks:"
echo "  - \"hooks/before-activation.sh\""
echo "  - \"hooks/after-activation.sh\""
echo ""

if [ "$source_type" = "git" ]; then
    echo "After that, either:"
    echo "- Run \"lit pull\" to deploy the current branch ($default_branch)"
    echo "- Run \"lit checkout <branch>\" to deploy a different branch"
elif [ "$source_type" = "bundle" ]; then
    echo "After that, run \"lit pull\" to download and deploy the bundle"
fi

echo ""
