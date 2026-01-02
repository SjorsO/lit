#!/bin/bash

set -e

tests_base_path="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"

if [ ! -f "$tests_base_path/run-tests.sh" ]; then
    printf 'Could not determine tests directory\n'

    exit 1
fi

case_filter="$1"
started_at=$(date +%s)

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

case_files=()
max_name_length=0

shopt -s nullglob
for case_file in "$tests_base_path/cases/${case_filter}"*.sh; do
    case_files+=("$case_file")
    case_name=$(basename "$case_file")

    if [ ${#case_name} -gt "$max_name_length" ]; then
        max_name_length=${#case_name}
    fi
done

if [ ${#case_files[@]} -eq 0 ]; then
    printf 'No tests found matching "%s"\n' "$case_filter"

    exit 1
fi

failed_tests=()
failed_outputs=()

for case_file in "${case_files[@]}"; do
    reset_environment "$tests_base_path"

    case_name=$(basename "$case_file")

    printf '%-*s    ' "$max_name_length" "$case_name"

    mkdir -p "$tests_base_path/world/case"

    original_directory=$(pwd)
    set +e
    cd "$tests_base_path/world/case" || exit 1
    output=$(bash "$tests_base_path/start-case.sh" "$tests_base_path/world" "$case_file" 2>&1)
    status_code=$?
    set -e
    cd "$original_directory" || exit 1

    if [ "$status_code" -eq 0 ]; then
        printf '✓\n'
    else
        printf '✗\n'
        failed_tests+=("$case_name")
        failed_outputs+=("$output")
    fi
done

if [ ${#failed_tests[@]} -gt 0 ]; then
    printf '\n'
    for i in "${!failed_tests[@]}"; do
        printf '=== %s ===\n' "${failed_tests[$i]}"
        printf '%s\n\n' "${failed_outputs[$i]}"
    done

    printf '%s test(s) failed (in %s seconds)\n' "${#failed_tests[@]}" "$(( $(date +%s) - started_at ))"

    exit 1
fi

printf '\nAll tests passed (in %s seconds)\n' "$(( $(date +%s) - started_at ))"
