#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

source "$lit_base_path/scripts/helpers.sh"

git_repository_url="$(get_file_value "$project_base_path/lit/git-repository-url")"
current_branch="$(get_file_value "$project_base_path/lit/current-branch")"
current_commit="$(get_file_value "$project_base_path/lit/current-commit")"

echo " Git repository url: $git_repository_url"
echo "     Current branch: $current_branch"
echo "     Current commit: $current_commit"

if [ -f "$project_base_path/lit/reusing-enabled" ]; then
    echo "   Reusing releases: enabled"
else
    echo "   Reusing releases: disabled"
fi

if [ -f "$lit_base_path/data/telemetry-enabled" ]; then
    echo "Anonymous telemetry: enabled"
else
    echo "Anonymous telemetry: disabled"
fi
