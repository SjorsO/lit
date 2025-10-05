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

if [ ! -d "$project_base_path/lit/logs" ]; then
    mkdir -p "$project_base_path/lit/logs"
fi

if [ ! -f "$project_base_path/lit/max-log-file-size" ]; then
    echo "10485760" > "$project_base_path/lit/max-log-file-size"
fi

max_log_file_size=$(get_file_value "$project_base_path/lit/max-log-file-size")

rotate_log_file "$max_log_file_size" "$project_base_path/lit/logs/lit.log"
rotate_log_file "$max_log_file_size" "$project_base_path/lit/logs/output.log"

echo "[$(get_human_timestamp)] lit $*" >> "$project_base_path/lit/logs/lit.log"
echo "[$(get_human_timestamp)] lit $*" >> "$project_base_path/lit/logs/output.log"

# Write all output to both stdout and a log file
exec > >(tee -a "$project_base_path/lit/logs/output.log") 2>&1

lock_directory_path="$project_base_path/lit/lit-is-currently-running"
has_created_lock_directory=false

on_exit() {
    script_status_code=$?

    if [[ "$has_created_lock_directory" == true ]]; then
        rmdir "$lock_directory_path"
    fi

    echo "[$(get_human_timestamp)] Finished" >> "$project_base_path/lit/logs/output.log"

    exit "$script_status_code"
}
trap on_exit EXIT TERM

if [ -d "$lock_directory_path" ]; then
    echo "Another Lit command is currently running for this project, aborting..."
    echo "If this is wrong, manually run:"
    echo "    rmdir \"$lock_directory_path\""

    exit 1
fi

# Ensure we can't run multiple commands at the same time.
mkdir "$lock_directory_path"

has_created_lock_directory=true

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
