#!/bin/bash

# Detect if Lit is run via "source".
# Using "source" is necessary the first time Lit runs so we can register the alias.
if [ "${BASH_SOURCE[0]}" != "${0}" ]; then
    if [[ "${BASH_SOURCE[0]}" == */* ]]; then
        lit_base_path="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
    elif [ -f "$(pwd)/scripts/install.sh" ]; then
        lit_base_path="$(pwd)"
    else
        lit_base_path="$(pwd)/lit"
    fi

    if [ ! -f "$lit_base_path/data/installation-id" ]; then
        bash "$lit_base_path/scripts/install.sh" "$lit_base_path"

        if [ $? -eq 100 ]; then
            alias lit="$lit_base_path/lit.sh"
        fi

        unset lit_base_path

        return 0
    fi

    unset lit_base_path

    echo "Don't use \"source\" to run Lit, use \"bash\" instead"

    return 1
fi

set +e

lit_base_path="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
project_base_path="$(pwd)"
command="$1"

if [ ! -f "$lit_base_path/data/installation-id" ]; then
    echo "You are running Lit for the first time, you have to use \"source lit.sh\""

    exit 1
fi

if [ "$command" = "clone" ]; then
    bash "$lit_base_path/scripts/clone.sh" "$lit_base_path" "$project_base_path" "$2"

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

if [ ! -d "$project_base_path/logs" ]; then
    mkdir -p "$project_base_path/logs"
fi

if [ ! -f "$project_base_path/lit/max-log-file-size" ]; then
    echo "10485760" > "$project_base_path/lit/max-log-file-size"
fi

max_log_file_size=$(get_file_value "$project_base_path/lit/max-log-file-size")

rotate_log_file "$max_log_file_size" "$project_base_path/logs/lit.log"
rotate_log_file "$max_log_file_size" "$project_base_path/logs/lit-output.log"

echo "[$(get_human_timestamp)] lit $*" >> "$project_base_path/logs/lit.log"
echo "[$(get_human_timestamp)] lit $*" >> "$project_base_path/logs/lit-output.log"

# Don't log "git log" to "lit-output.log"
if [ "$command" = "log" ]; then
    git --git-dir "$(pwd)/current/.git" log "${@:2}"

    exit 0
fi

# Write all output to both stdout and a log file
exec > >(tee -a "$project_base_path/logs/lit-output.log") 2>&1

lock_directory_path="$project_base_path/lit/lit-is-currently-running"
has_created_lock_directory=false

on_exit() {
    script_status_code=$?

    if [[ "$has_created_lock_directory" == true ]]; then
        rmdir "$lock_directory_path"
    fi

    echo "[$(get_human_timestamp)] Finished" >> "$project_base_path/logs/lit-output.log"

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

echo "v1.0" > "$lit_base_path/data/lit-version"

if [ -f "$lit_base_path/data/telemetry-enabled" ] && [ ! -s "$lit_base_path/data/telemetry-salt" ]; then
    generate_uuid > "$lit_base_path/data/telemetry-salt"
fi

if [ "$command" = "pull" ]; then
    bash "$lit_base_path/scripts/pull.sh" "$lit_base_path" "$project_base_path" "$2" "$3"
elif [ "$command" = "checkout" ]; then
    bash "$lit_base_path/scripts/checkout.sh" "$lit_base_path" "$project_base_path" "$2" "$3"
elif [ "$command" = "status" ]; then
    bash "$lit_base_path/scripts/status.sh" "$lit_base_path" "$project_base_path"
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
