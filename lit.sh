#!/bin/bash

set -e

lit_base_path="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"

command="$1"

if [ "$command" = "pull" ]; then
    bash "$lit_base_path/scripts/pull.sh" "$lit_base_path" "$2"
elif [ "$command" = "init" ]; then
    bash "$lit_base_path/scripts/init.sh" "$lit_base_path" "$2"
elif [ "$command" = "checkout" ]; then
    bash "$lit_base_path/scripts/checkout.sh" "$lit_base_path" "$2" "$3"
elif [ "$command" = "log" ]; then
    if [ ! -d "$(pwd)/lit" ]; then
        echo "This is not a Lit directory"

        exit 1
    fi

    git --git-dir "$(pwd)/current/.git" log "${@:2}"
elif [ "$command" = "enable-reusing" ]; then
    bash "$lit_base_path/scripts/enable-reusing.sh" "$lit_base_path"
elif [ "$command" = "disable-reusing" ]; then
    bash "$lit_base_path/scripts/disable-reusing.sh" "$lit_base_path"
else
    cat "$lit_base_path/help.txt"
fi
