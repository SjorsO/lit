#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

source "$lit_base_path/scripts/helpers.sh"

if [ -f "$project_base_path/lit/caching-enabled" ]; then
    echo "Release caching is already enabled"
    
    exit 1
fi

touch "$project_base_path/lit/caching-enabled"

if [ ! -f "$project_base_path/hooks/before-caching.sh" ]; then
    cp "$lit_base_path/stubs/before-caching.sh.stub" "$project_base_path/hooks/before-caching.sh"

    echo "Created new hook \"$(basename "$project_base_path")/hooks/before-caching.sh\""    
    echo ""
    echo "Make sure to review and update these hooks:"
    echo "- \"hooks/before-caching.sh\""
    echo "- \"hooks/before-activation.sh\""
    echo "- \"hooks/after-activation.sh\""
    echo ""
fi

echo "Release caching enabled"
