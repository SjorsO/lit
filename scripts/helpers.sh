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
    local max_size=$1
    local log_file=$2

    if [[ ! -f "$log_file" ]]; then
        touch "$log_file"

        return
    fi

    file_size=$(stat "$(is_macos && echo "-f%z" || echo "-c%s")" "$log_file" 2>/dev/null || echo 0)

    if [[ $file_size -le $max_size ]]; then
        return
    fi

    [[ -f "${log_file%.log}.4.tar.gz" ]] && rm "${log_file%.log}.4.tar.gz"
    [[ -f "${log_file%.log}.3.tar.gz" ]] && mv "${log_file%.log}.3.tar.gz" "${log_file%.log}.4.tar.gz"
    [[ -f "${log_file%.log}.2.tar.gz" ]] && mv "${log_file%.log}.2.tar.gz" "${log_file%.log}.3.tar.gz"
    [[ -f "${log_file%.log}.1.log" ]] && tar -czf "${log_file%.log}.2.tar.gz" "${log_file%.log}.1.log" && rm "${log_file%.log}.1.log"
    mv "$log_file" "${log_file%.log}.1.log"

    touch "$log_file"
}
