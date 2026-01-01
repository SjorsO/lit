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
