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

if [ ! -f "$project_base_path/git-repository-url" ] && [ ! -f "$project_base_path/bundle-url" ]; then
    printf 'This is not a Lit directory\n'

    exit 1
fi

if [ ! -d "$project_base_path/storage" ] && [ ! -d "$project_base_path/shared/storage" ]; then
    printf 'This looks like a Lit directory, but the storage directory does not exist\n'

    exit 1
fi

source "$lit_base_path/scripts/helpers.sh"

if [ ! -d "$project_base_path/logs" ]; then
    mkdir -p "$project_base_path/logs"
fi

lock_directory_path="$project_base_path/lit-is-currently-running"

# Allow running "lit flush-opcache" from inside a "lit deploy" without it logging to "lit.log"
if [ "$command" = "flush-opcache" ] && [ "$__lit_allow_flush_opcache_without_lock" = "true" ]; then
    bash "$lit_base_path/scripts/flush-opcache.sh" "$project_base_path"

    exit $?
fi

start_time=$(current_time_in_ms)

acquire_lit_log_lock
echo "[$(get_human_timestamp)] lit $* (pending:$$)" >> "$project_base_path/logs/lit.log"
release_lit_log_lock

echo "[$(get_human_timestamp)] lit $*" >> "$project_base_path/logs/lit-output.log"

# Write all output to both stdout and a log file
exec > >(tee -a "$project_base_path/logs/lit-output.log") 2>&1

has_created_lock_directory=false

on_exit() {
    script_status_code=$?

    log_result=""

    if [ -f "$project_base_path/current-run-result" ]; then
        log_result=$(cat "$project_base_path/current-run-result")

        rm -f "$project_base_path/current-run-result"
    fi

    replace_log_placeholder "$$" "$log_result" "$(($(current_time_in_ms) - start_time))"

    if [[ "$has_created_lock_directory" == true ]]; then
        rmdir "$lock_directory_path"
    fi

    exit "$script_status_code"
}
trap on_exit EXIT TERM

if [ -d "$lock_directory_path" ]; then
    printf 'Another Lit command is currently running for this project, aborting...\n'
    printf 'If this is wrong, manually run:\n'
    printf '    rmdir "%s"\n' "$lock_directory_path"

    replace_log_placeholder "$$" "aborted, another lit command is currently running" "$(($(current_time_in_ms) - start_time))"

    exit 1
fi

# Ensure we can't run multiple commands at the same time.
mkdir "$lock_directory_path"

has_created_lock_directory=true

# A git pre-commit hook automatically increments this version number.
echo "67" > "$lit_base_path/data/lit-version"

if [ -f "$lit_base_path/data/telemetry-enabled" ] && [ ! -s "$lit_base_path/data/telemetry-salt" ]; then
    uuidgen | tr '[:upper:]' '[:lower:]' > "$lit_base_path/data/telemetry-salt"
fi

if [ "$command" = "deploy" ]; then
    export __lit_allow_flush_opcache_without_lock="true"
    bash "$lit_base_path/scripts/deploy.sh" "$lit_base_path" "$project_base_path" "$2" "$3"
elif [ "$command" = "checkout" ]; then
    export __lit_allow_flush_opcache_without_lock="true"
    bash "$lit_base_path/scripts/checkout.sh" "$lit_base_path" "$project_base_path" "$2" "$3"
elif [ "$command" = "enable-git-release-caching" ]; then
    bash "$lit_base_path/scripts/enable-git-release-caching.sh" "$lit_base_path" "$project_base_path"
elif [ "$command" = "disable-git-release-caching" ]; then
    bash "$lit_base_path/scripts/disable-git-release-caching.sh" "$lit_base_path" "$project_base_path"
elif [ "$command" = "enable-telemetry" ]; then
    bash "$lit_base_path/scripts/enable-telemetry.sh" "$lit_base_path" "$project_base_path"
elif [ "$command" = "disable-telemetry" ]; then
    bash "$lit_base_path/scripts/disable-telemetry.sh" "$lit_base_path" "$project_base_path"
elif [ "$command" = "flush-opcache" ]; then
    bash "$lit_base_path/scripts/flush-opcache.sh" "$project_base_path"
elif [ "$command" = "opcache-status" ]; then
    bash "$lit_base_path/scripts/opcache-status.sh" "$project_base_path" "$2"
else
    echo "failed (unknown command)" > "$project_base_path/current-run-result"

    cat "$lit_base_path/help.txt"

    exit 1
fi
