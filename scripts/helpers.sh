#!/bin/bash

generate_uuid() {
    uuidgen | tr '[:upper:]' '[:lower:]'
}

get_file_value() {
    # Remove new lines and trim whitespace
    sed -e 's/^[[:space:]]*//; s/[[:space:]]*$//' < "$1"
}

get_human_timestamp() {
    echo "$(date '+%Y-%m-%d %H:%M:%S').$(date '+%N' | cut -c1-3)"
}

is_macos() {
    [[ "$OSTYPE" == "darwin"* ]]
}

rotate_log_file() {
    local log_file=$1

    if [[ ! -f "$log_file" ]]; then
        touch "$log_file"

        return
    fi

    if [[ $(stat "$(is_macos && echo "-f%z" || echo "-c%s")" "$log_file" 2>/dev/null || echo 0) -le 10485760 ]]; then
        return
    fi

    [[ -f "${log_file%.log}.2.log" ]] && rm "${log_file%.log}.2.log"
    [[ -f "${log_file%.log}.1.log" ]] && mv "${log_file%.log}.1.log" "${log_file%.log}.2.log"

    mv "$log_file" "${log_file%.log}.1.log"

    touch "$log_file"
}
