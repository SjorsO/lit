#!/bin/bash

get_file_value() {
    # Remove new lines and trim whitespace
    sed -e 's/^[[:space:]]*//; s/[[:space:]]*$//' < "$1"
}

get_human_timestamp() {
    echo "$(date '+%Y-%m-%d %H:%M:%S')"
}

is_macos() {
    [[ "$OSTYPE" == "darwin"* ]]
}

acquire_lit_log_lock() {
    local attempts=0

    while ! mkdir "$project_base_path/lit/lit-log-lock" 2>/dev/null; do
        sleep 0.1

        attempts=$((attempts + 1))

        if [ "$attempts" -ge 10 ]; then
            break
        fi
    done
}

release_lit_log_lock() {
    if [ -d "$project_base_path/lit/lit-log-lock" ]; then
        rmdir "$project_base_path/lit/lit-log-lock"
    fi
}

replace_log_placeholder() {
    local pid="$1"
    local result="$2"
    local duration="$3"
    local log_file="$project_base_path/logs/lit.log"

    acquire_lit_log_lock

    if [ -n "$result" ]; then
        sed "s/ (pending:$pid)\$/ → $result (in ${duration}s)/" "$log_file" > "$log_file.tmp" && mv "$log_file.tmp" "$log_file"
    else
        sed "s/ (pending:$pid)\$//" "$log_file" > "$log_file.tmp" && mv "$log_file.tmp" "$log_file"
    fi

    release_lit_log_lock
}
