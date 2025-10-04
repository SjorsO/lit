#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$(pwd)"

source "$lit_base_path/scripts/helpers.sh"

if [ ! -d "$project_base_path/lit" ]; then
    echo "This is not a Lit directory"

    exit 1
fi

if [ -f "$project_base_path/lit/reusing-enabled" ]; then
    echo "Reusing is already enabled"
    
    exit 1
fi

touch "$project_base_path/lit/reusing-enabled"

if [ ! -f "$project_base_path/lit/hooks/before-storing-for-reuse.sh" ]; then
    cp "$lit_base_path/stubs/before-storing-for-reuse.sh.stub" "$project_base_path/lit/hooks/before-storing-for-reuse.sh"

    echo "Created new hook \"$(basename "$project_base_path")/lit/hooks/before-storing-for-reuse.sh\""    
    echo ""
fi

echo "Enabled reusing cached releases"
