#!/bin/bash

set -e

tests_base_path="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"

if [ ! -f "$tests_base_path/run-tests.sh" ]; then
    printf 'Could not determine tests directory\n'

    exit 1
fi

case_filter="$1"
started_at=$(date +%s)

setup_world() {
    local world_path="$1"
    # shellcheck disable=SC2155
    local lit_source_path="$(dirname "$tests_base_path")"

    rm -rf "$world_path"

    mkdir -p "$world_path/lit/data"
    mkdir -p "$world_path/case"

    cp -r "$lit_source_path/scripts" "$world_path/lit/scripts"
    cp -r "$lit_source_path/stubs" "$world_path/lit/stubs"
    cp "$lit_source_path/lit.sh" "$world_path/lit/lit.sh"
    cp "$lit_source_path/help.txt" "$world_path/lit/help.txt"

    echo "testing" > "$world_path/lit/data/installation-id"
}

case_files=()
max_name_length=0

shopt -s nullglob

for case_file in "$tests_base_path/cases/"*.sh; do
    case_name=$(basename "$case_file")
    if [ ${#case_name} -gt "$max_name_length" ]; then
        max_name_length=${#case_name}
    fi
done

for case_file in "$tests_base_path/cases/${case_filter}"*.sh; do
    case_files+=("$case_file")
done

if [ ${#case_files[@]} -eq 0 ]; then
    printf 'No tests found matching "%s"\n' "$case_filter"

    exit 1
fi

shuffled_files=()
while IFS= read -r file; do
    shuffled_files+=("$file")
done < <(printf '%s\n' "${case_files[@]}" | sort -R)
case_files=("${shuffled_files[@]}")

failed_tests=()
failed_outputs=()

worlds_path="$tests_base_path/worlds"

mkdir -p "$worlds_path"

for case_file in "${case_files[@]}"; do
    case_name=$(basename "$case_file")
    case_number="${case_name%%-*}"
    world_path="$worlds_path/world-$case_number"

    setup_world "$world_path"

    printf '%-*s    ' "$max_name_length" "$case_name"

    original_directory=$(pwd)
    set +e
    cd "$world_path/case" || exit 1
    output=$(bash "$tests_base_path/start-case.sh" "$world_path" "$case_file" 2>&1)
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
