#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"
new_branch="$3"

if [ -z "$new_branch" ] || [ -n "$4" ]; then
    printf 'usage: lit checkout <branch>\n'

    exit 1
fi

source "$lit_base_path/scripts/helpers.sh"

source_type="$(get_file_value "$project_base_path/lit/source-type")"

if [ "$source_type" != "git" ]; then
    printf 'Cannot change branches because you are not deploying from git\n'

    exit 1
fi

git_repository_url="$(get_file_value "$project_base_path/lit/git-repository-url")"
current_branch="$(get_file_value "$project_base_path/lit/current-branch")"

if [ "$current_branch" = "$new_branch" ]; then
    printf 'Current branch is already "%s"\n' "$new_branch"

    exit 1
fi

printf 'Switching to branch "%s"... ' "$new_branch"

remote_branch_info=$(git ls-remote --symref "$git_repository_url" "$new_branch")

printf '\n'

if [ -z "$remote_branch_info" ]; then
    printf 'Branch "%s" does not exist on remote\n' "$new_branch"

    exit 1
fi

current_remote_commit=$(echo "$remote_branch_info" | grep -v "ref: refs/heads/" | cut -f1)

echo "$new_branch" > "$project_base_path/lit/current-branch"

bash "$lit_base_path/scripts/pull.sh" "$lit_base_path" "$project_base_path" "--use-commit-from-checkout" "$current_remote_commit"
