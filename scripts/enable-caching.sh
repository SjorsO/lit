#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

source "$lit_base_path/scripts/helpers.sh"

source_type="$(get_source_type "$project_base_path")"

if [ "$source_type" != "git" ]; then
    printf 'Release caching is only available when deploying from git\n'

    exit 1
fi

if [ -f "$project_base_path/lit/caching-enabled" ]; then
    printf 'Release caching for git is already enabled\n'

    exit 1
fi

touch "$project_base_path/lit/caching-enabled"

printf 'Release caching for git enabled\n'
printf '\n'

if [ ! -f "$project_base_path/hooks/before-caching.sh" ]; then
    cp "$lit_base_path/stubs/hooks-for-git/before-caching.sh.stub" "$project_base_path/hooks/before-caching.sh"

    printf 'Created new hook "%s/hooks/before-caching.sh"\n' "$(basename "$project_base_path")"
    printf '\n'
fi

printf 'Review and update these hooks:\n'
printf -- '- "hooks/before-caching.sh"\n'
printf -- '- "hooks/before-release.sh"\n'
printf -- '- "hooks/after-release.sh"\n'
printf '\n'
