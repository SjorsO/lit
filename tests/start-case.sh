#!/bin/bash

world_path="$1"
case_file="$2"

test_helpers_path="$(dirname "$(realpath "${BASH_SOURCE[0]}")")/test-helpers.sh"

if [ ! -f "$test_helpers_path" ]; then
    printf 'Could not find test-helpers.sh\n'

    exit 1
fi

# shellcheck disable=SC1090
source "$test_helpers_path"

lit() {
    bash "$world_path/lit/lit.sh" "$@";
}

# shellcheck disable=SC1090
source "$case_file"

# If we're inside of a directory that has been deleted, then this error happens.
# This should never happen, so assert this after every test.
for log_file in "$world_path"/case/*/logs/lit-output.log; do
    if [ -f "$log_file" ]; then
        if grep -q "shell-init\|getcwd" "$log_file"; then
            printf 'Error: Found shell-init or getcwd error in %s\n' "$log_file"
            grep "shell-init\|getcwd" "$log_file"
            exit 1
        fi
    fi
done
