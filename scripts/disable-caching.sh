#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

source "$lit_base_path/scripts/helpers.sh"

if [ ! -f "$project_base_path/lit/caching-enabled" ]; then
    printf 'Release caching is already disabled\n'

    exit 1
fi

rm "$project_base_path/lit/caching-enabled"

printf 'Release caching disabled\n'
