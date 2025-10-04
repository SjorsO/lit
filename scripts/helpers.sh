#!/bin/bash

generate_uuid() {
    uuidgen | tr '[:upper:]' '[:lower:]'
}

get_file_value() {
    # Remove new lines and trim whitespace
    sed -e 's/^[[:space:]]*//; s/[[:space:]]*$//' < "$1"
}
