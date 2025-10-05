#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"
new_branch="$3"

if [ -z "$new_branch" ] || [ -n "$4" ]; then
    echo "usage: lit checkout <branch>"

    exit 1
fi

source "$lit_base_path/scripts/helpers.sh"


git_repository_url="$(get_file_value "$project_base_path/lit/git-repository-url")"
current_branch="$(get_file_value "$project_base_path/lit/current-branch")"

if [ "$current_branch" = "$new_branch" ]; then
    echo "Current branch is already \"$new_branch\""

    exit 1
fi

remote_branch_info=$(git ls-remote --symref "$git_repository_url" "$new_branch")

if [ -z "$remote_branch_info" ]; then
    echo "Branch \"$new_branch\" does not exist on remote"

    exit 1
fi

echo "$new_branch" > "$project_base_path/lit/current-branch"

echo "Switched to branch \"$new_branch\""

bash "$lit_base_path/scripts/pull.sh" "$lit_base_path" "$project_base_path"
