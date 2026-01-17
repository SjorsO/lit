#!/bin/bash

set -e

if [ $# -gt 0 ]; then
    printf 'The parallel test runner does not support arguments\n'

    exit 1
fi

tests_base_path="$(dirname "$(realpath "${BASH_SOURCE[0]}")")"

if [ ! -f "$tests_base_path/run-tests-in-parallel.sh" ]; then
    printf 'Could not determine tests directory\n'

    exit 1
fi

started_at=$(date +%s)
max_number_of_concurrent_tests=999

lit_source_path="$(dirname "$tests_base_path")"
worlds_path="$tests_base_path/worlds"

rm -rf "$worlds_path"
mkdir -p "$worlds_path"

setup_world() {
    local world_path="$1"

    mkdir -p "$world_path/lit/data"
    mkdir -p "$world_path/case"

    cp -r "$lit_source_path/scripts" "$world_path/lit/scripts"
    cp -r "$lit_source_path/stubs" "$world_path/lit/stubs"
    cp "$lit_source_path/lit.sh" "$world_path/lit/lit.sh"
    cp "$lit_source_path/help.txt" "$world_path/lit/help.txt"

    echo "testing" > "$world_path/lit/data/installation-id"
}

run_test() {
    local case_file="$1"
    local case_name="$2"
    local case_number="$3"
    local world_path="$worlds_path/world-$case_number"

    setup_world "$world_path"

    cd "$world_path/case" || exit 1

    if bash "$tests_base_path/start-case.sh" "$world_path" "$case_file" > "$world_path/output.txt" 2>&1; then
        echo "pass" > "$world_path/result.txt"
    else
        echo "fail" > "$world_path/result.txt"
    fi
}

case_files=()
case_numbers=()
max_name_length=0

for case_file in "$tests_base_path/cases/"*.sh; do
    case_files+=("$case_file")
    case_name=$(basename "$case_file")
    case_number="${case_name%%-*}"
    case_numbers+=("$case_number")

    if [ ${#case_name} -gt "$max_name_length" ]; then
        max_name_length=${#case_name}
    fi
done

if [ ${#case_files[@]} -le $max_number_of_concurrent_tests ]; then
    printf 'Running all %s tests in parallel...\n\n' "${#case_files[@]}"
else
    printf 'Running %s tests in parallel, batches of %s...\n\n' "${#case_files[@]}" "$max_number_of_concurrent_tests"
fi

running_jobs=()

for i in "${!case_files[@]}"; do
    run_test "${case_files[$i]}" "$(basename "${case_files[$i]}")" "${case_numbers[$i]}" &

    running_jobs+=($!)

    if [ ${#running_jobs[@]} -ge $max_number_of_concurrent_tests ]; then
        wait "${running_jobs[@]}"

        running_jobs=()
    fi
done

if [ ${#running_jobs[@]} -gt 0 ]; then
    wait "${running_jobs[@]}"
fi

# Collect all lit.log and lit-output.log files
mkdir -p "$worlds_path/_lit-logs"
mkdir -p "$worlds_path/_lit-output-logs"

for world_dir in "$worlds_path"/world-*; do
    case_number=$(basename "$world_dir" | sed 's/world-//')

    lit_log=$(find "$world_dir" -name "lit.log" -type f 2>/dev/null | head -1)
    if [ -n "$lit_log" ]; then
        cp "$lit_log" "$worlds_path/_lit-logs/$case_number-lit.log"
    fi

    lit_output_log=$(find "$world_dir" -name "lit-output.log" -type f 2>/dev/null | head -1)
    if [ -n "$lit_output_log" ]; then
        cp "$lit_output_log" "$worlds_path/_lit-output-logs/$case_number-lit-output.log"
    fi
done

failed_tests=()
failed_outputs=()

for i in "${!case_files[@]}"; do
    case_file="${case_files[$i]}"
    case_name=$(basename "$case_file")
    case_number="${case_numbers[$i]}"
    world_path="$worlds_path/world-$case_number"

    printf '%-*s    ' "$max_name_length" "$case_name"

    if [ -f "$world_path/result.txt" ] && [ "$(cat "$world_path/result.txt")" = "pass" ]; then
        printf '✓\n'
    else
        printf '✗\n'
        failed_tests+=("$case_name")
        if [ -f "$world_path/output.txt" ]; then
            failed_outputs+=("$(cat "$world_path/output.txt")")
        else
            failed_outputs+=("(no output)")
        fi
    fi
done

if [ ${#failed_tests[@]} -gt 0 ]; then
    printf '\n'
    for i in "${!failed_tests[@]}"; do
        printf '=== %s ===\n' "${failed_tests[$i]}"
        printf '%s\n\n' "${failed_outputs[$i]}"
    done

    printf '%s test(s) failed (in %s seconds)\n' "${#failed_tests[@]}" "$(( $(date +%s) - started_at ))"
    printf '\nLogs stored separately for manual review:\n'
    printf -- '- worlds/_lit-logs/\n'
    printf -- '- worlds/_lit-output-logs/\n\n'

    exit 1
fi

printf '\nAll tests passed (in %s seconds)\n' "$(( $(date +%s) - started_at ))"
printf '\nLogs stored separately for manual review:\n'
printf -- '- worlds/_lit-logs/\n'
printf -- '- worlds/_lit-output-logs/\n\n'
