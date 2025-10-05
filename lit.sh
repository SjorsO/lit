#!/bin/bash

set -e

lit_base_path="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
project_base_path="$(pwd)"

command="$1"

if [ "$command" = "init" ]; then
    bash "$lit_base_path/scripts/init.sh" "$lit_base_path" "$project_base_path" "$2"

    exit $?
elif [ "$command" = "help" ]; then
    cat "$lit_base_path/help.txt"

    exit 0
fi

if [ ! -d "$project_base_path/lit" ]; then
    echo "This is not a Lit directory"

    exit 1
fi

source "$lit_base_path/scripts/helpers.sh"

if [ ! -d "$lit_base_path/data" ]; then
    mkdir "$lit_base_path/data"
fi

echo "v1.0" > "$lit_base_path/data/lit-version"

if [ ! -f "$lit_base_path/data/installation-id" ]; then
    generate_uuid > "$lit_base_path/data/installation-id"
fi

if [ -f "$lit_base_path/data/telemetry-enabled" ] && [ ! -s "$lit_base_path/data/telemetry-salt" ]; then
    generate_uuid > "$lit_base_path/data/telemetry-salt"
fi

log_info "$project_base_path" "Running: lit $*"

if [ "$command" = "pull" ]; then
    bash "$lit_base_path/scripts/pull.sh" "$lit_base_path" "$project_base_path" "$2"
elif [ "$command" = "checkout" ]; then
    bash "$lit_base_path/scripts/checkout.sh" "$lit_base_path" "$project_base_path" "$2" "$3"
elif [ "$command" = "status" ]; then
    bash "$lit_base_path/scripts/status.sh" "$lit_base_path" "$project_base_path"
elif [ "$command" = "log" ]; then
    if [ ! -d "$(pwd)/lit" ]; then
        echo "This is not a Lit directory"

        exit 1
    fi

    git --git-dir "$(pwd)/current/.git" log "${@:2}"
elif [ "$command" = "enable-reusing" ]; then
    bash "$lit_base_path/scripts/enable-reusing.sh" "$lit_base_path" "$project_base_path"
elif [ "$command" = "disable-reusing" ]; then
    bash "$lit_base_path/scripts/disable-reusing.sh" "$lit_base_path" "$project_base_path"
elif [ "$command" = "enable-telemetry" ]; then
    bash "$lit_base_path/scripts/enable-telemetry.sh" "$lit_base_path" "$project_base_path"
elif [ "$command" = "disable-telemetry" ]; then
    bash "$lit_base_path/scripts/disable-telemetry.sh" "$lit_base_path" "$project_base_path"
else
    cat "$lit_base_path/help.txt"
fi

log_info "$project_base_path" "Finished"
