#!/bin/bash

set -e

lit_base_path="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"

if [ ! -d "$lit_base_path/data" ]; then
    mkdir "$lit_base_path/data"    
fi

command="$1"

if [ "$command" = "pull" ]; then
    bash "$lit_base_path/scripts/pull.sh" "$lit_base_path"
elif [ "$command" = "init" ]; then
    bash "$lit_base_path/scripts/init.sh" "$lit_base_path" "$2"
else
    cat "$lit_base_path/scripts/help.txt"
fi
