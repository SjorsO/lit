#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

source "$lit_base_path/scripts/helpers.sh"

source_type="$(get_source_type "$project_base_path")"

lines=()

if [ "$source_type" = "git" ]; then
    git_repository_url="$(get_file_value "$project_base_path/lit/git-repository-url")"
    current_branch="$(get_file_value "$project_base_path/lit/current-branch")"
    current_commit="$(get_file_value "$project_base_path/lit/current-commit")"
    caching_status=$([ -f "$project_base_path/lit/git-release-caching-enabled" ] && echo "enabled" || echo "disabled")

    while IFS= read -r line; do
        lines+=("$line")
    done <<EOF
        Deploying from: git
    Git repository url: $git_repository_url
        Current branch: $current_branch
        Current commit: $current_commit
       Release caching: $caching_status
EOF
elif [ "$source_type" = "bundle" ]; then
    bundle_url="$(get_file_value "$project_base_path/lit/bundle-url")"
    current_bundle_hash="$(get_file_value "$project_base_path/lit/current-bundle-hash")"

    while IFS= read -r line; do
        lines+=("$line")
    done <<EOF
     Deploying from: bundle
         Bundle URL: $bundle_url
    Bundle hash URL: ${bundle_url}.hash
Current bundle hash: $current_bundle_hash
EOF
fi

for line in "${lines[@]}"; do
    if [ ${#line} -gt "${max_length:-0}" ]; then
        max_length=${#line}
    fi
done

if [ "${max_length}" -lt 76 ]; then
    max_length=76
fi

box_width=$((max_length + 2))
horizontal_line=$(printf '─%.0s' $(seq 1 $box_width))

printf '╭%s╮\n' "$horizontal_line"

for line in "${lines[@]}"; do
    printf '│ %-'"${max_length}"'s │\n' "$line"
done

printf '╰%s╯\n' "$horizontal_line"
