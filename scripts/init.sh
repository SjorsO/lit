#!/bin/bash

set -e

trap 'tput cnorm' EXIT INT TERM

echo ""

# if [ "$(ls -A . 2>/dev/null)" ]; then
#     echo "Current directory is not empty."
#     echo ""
#     echo "Please try again in an empty directory."
#     echo ""
#     exit 1
# fi

lit_base_path="$1"
project_base_path="$(pwd)"

source "$lit_base_path/scripts/helpers.sh"

# read -p "Enter a Git repository URL: " git_repository_url
git_repository_url="git@github.com:Revenly/tratta-backend.git"

mkdir -p "$project_base_path/lit"

echo "$git_repository_url" > "$project_base_path/lit/git-repository-url"

# tput civis

# git clone --depth 1 "$git_repository_url" initial-clone > /dev/null 2>&1 &

# show_spinner $! "Cloning \"$git_repository_url\""

git --git-dir="$project_base_path/initial-clone/.git" \
    rev-parse --abbrev-ref HEAD > "$project_base_path/lit/current-branch"

git --git-dir="$project_base_path/initial-clone/.git" \
    rev-parse HEAD > "$project_base_path/lit/current-commit"
