#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$(pwd)"

source "$lit_base_path/scripts/helpers.sh"

if [ ! -d "$project_base_path/lit" ]; then
    echo "This is not a Lit directory"

    exit 1
fi

if [ ! -f "$project_base_path/lit/reusing-enabled" ]; then
    echo "Reusing is already disabled"
    
    exit 1
fi

rm "$project_base_path/lit/reusing-enabled"

echo "Disabled reusing cached releases"
