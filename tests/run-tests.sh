#!/bin/bash

set -e

tests_base_path="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"

if [ ! -f "$tests_base_path/run-tests.sh" ]; then
    printf 'Could not determine tests directory\n'

    exit 1
fi

reset_environment() {
    local tests_base_path="$1"
    local world_path="$tests_base_path/world"
    # shellcheck disable=SC2155
    local lit_source_path="$(dirname "$tests_base_path")"

    rm -rf "$world_path"

    mkdir -p "$world_path/lit/data"

    cp -r "$lit_source_path/scripts" "$world_path/lit/scripts"
    cp -r "$lit_source_path/stubs" "$world_path/lit/stubs"
    cp "$lit_source_path/lit.sh" "$world_path/lit/lit.sh"
    cp "$lit_source_path/help.txt" "$world_path/lit/help.txt"

    echo "testing" > "$world_path/lit/data/installation-id"
}

for case_file in "$tests_base_path/cases/"*.sh; do
    reset_environment "$tests_base_path"

    case_name=$(basename "$case_file")

    printf 'Running %s... ' "$case_name"

    mkdir -p "$tests_base_path/world/case"

    original_directory=$(pwd)
    set +e
    cd "$tests_base_path/world/case" || exit 1
    output=$(bash "$case_file" "$tests_base_path/world" "$tests_base_path/test-helpers.sh" 2>&1)
    status_code=$?
    set -e
    cd "$original_directory" || exit 1

    if [ "$status_code" -eq 0 ]; then
        printf '✓\n'
    else
        printf '✗\n'
        printf '%s\n' "$output"

        exit 1
    fi
done

printf '\nAll tests passed\n'
