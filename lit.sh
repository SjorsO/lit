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

    printf 'Do not use "source" to run Lit, use "bash" instead\n'

    return 1
fi

set +e

lit_base_path="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"
project_base_path="$(pwd)"
command="$1"

if [ ! -f "$lit_base_path/data/installation-id" ]; then
    printf 'You are running Lit for the first time, run "source lit.sh" instead\n'

    exit 1
fi

if [ "$command" = "init" ]; then
    bash "$lit_base_path/scripts/init.sh" "$lit_base_path" "$project_base_path" "$2" "$3" "$4"

    exit $?
elif [ "$command" = "help" ]; then
    cat "$lit_base_path/help.txt"

    exit 0
fi

# Lit's installation directory is also called "lit", but that isn't actually a lit directory. So check
# if it doesn't contain "lit.sh" too.
if [ ! -d "$project_base_path/lit" ] || [ -f "$project_base_path/lit/lit.sh" ]; then
    printf 'This is not a Lit directory\n'

    exit 1
fi

source "$lit_base_path/scripts/helpers.sh"

if [ ! -d "$project_base_path/logs" ]; then
    mkdir -p "$project_base_path/logs"
fi

echo "[$(get_human_timestamp)] lit $*" >> "$project_base_path/logs/lit.log"
echo "[$(get_human_timestamp)] lit $*" >> "$project_base_path/logs/lit-output.log"

# Don't log "git log" to "lit-output.log"
if [ "$command" = "log" ]; then
    if [ ! -d "$project_base_path/current/.git" ]; then
        printf 'No git repository found in the current release\n'

        exit 1
    fi

    git --git-dir "$project_base_path/current/.git" log "${@:2}"

    exit 0
fi

# Run flush-opcache before the lock so it can run during a deploy
if [ "$command" = "flush-opcache" ]; then
    # If we call "flush-opcache" during a deployment, then logging is already set up. If we call this
    # logging code twice then we log everything twice.
    if [ ! -d "$project_base_path/lit/lit-is-currently-running" ]; then
        exec > >(tee -a "$project_base_path/logs/lit-output.log") 2>&1
    fi

    bash "$lit_base_path/scripts/flush-opcache.sh" "$project_base_path"

    exit $?
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
    printf 'Another Lit command is currently running for this project, aborting...\n'
    printf 'If this is wrong, manually run:\n'
    printf '    rmdir "%s"\n' "$lock_directory_path"

    echo "[$(get_human_timestamp)] Aborted because another Lit command is currently running" >> "$project_base_path/logs/lit.log"

    exit 1
fi

# Ensure we can't run multiple commands at the same time.
mkdir "$lock_directory_path"

has_created_lock_directory=true

# This version number is automatically incremented with a git pre-commit hook.
echo "11" > "$lit_base_path/data/lit-version"

if [ -f "$lit_base_path/data/telemetry-enabled" ] && [ ! -s "$lit_base_path/data/telemetry-salt" ]; then
    uuidgen | tr '[:upper:]' '[:lower:]' > "$lit_base_path/data/telemetry-salt"
fi

if [ "$command" = "deploy" ]; then
    bash "$lit_base_path/scripts/deploy.sh" "$lit_base_path" "$project_base_path" "$2" "$3"
elif [ "$command" = "checkout" ]; then
    bash "$lit_base_path/scripts/checkout.sh" "$lit_base_path" "$project_base_path" "$2" "$3"
elif [ "$command" = "status" ]; then
    bash "$lit_base_path/scripts/status.sh" "$lit_base_path" "$project_base_path"
elif [ "$command" = "enable-caching" ]; then
    bash "$lit_base_path/scripts/enable-caching.sh" "$lit_base_path" "$project_base_path"
elif [ "$command" = "disable-caching" ]; then
    bash "$lit_base_path/scripts/disable-caching.sh" "$lit_base_path" "$project_base_path"
elif [ "$command" = "enable-telemetry" ]; then
    bash "$lit_base_path/scripts/enable-telemetry.sh" "$lit_base_path" "$project_base_path"
elif [ "$command" = "disable-telemetry" ]; then
    bash "$lit_base_path/scripts/disable-telemetry.sh" "$lit_base_path" "$project_base_path"
else
    cat "$lit_base_path/help.txt"
fi
