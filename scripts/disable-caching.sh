#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

source "$lit_base_path/scripts/helpers.sh"

if [ ! -f "$project_base_path/lit/caching-enabled" ]; then
    printf 'Release caching for git is already disabled\n'

    exit 1
fi

rm "$project_base_path/lit/caching-enabled"

printf 'Release caching for git disabled\n'
printf '\n'
printf 'Review and update these hooks:\n'
printf -- '- "hooks/before-release.sh"\n'
printf -- '- "hooks/after-release.sh"\n'
printf '\n'

if [ -f "$project_base_path/hooks/before-caching.sh" ]; then
    printf 'This hook will not be used anymore: "%s/hooks/before-caching.sh"\n' "$(basename "$project_base_path")"
    printf '\n'
fi
