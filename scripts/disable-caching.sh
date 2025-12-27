#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

source "$lit_base_path/scripts/helpers.sh"

if [ ! -f "$project_base_path/lit/caching-enabled" ]; then
    echo "Release caching is already disabled"
    
    exit 1
fi

rm "$project_base_path/lit/caching-enabled"

echo "Release caching disabled"
