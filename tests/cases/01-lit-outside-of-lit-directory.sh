#!/bin/bash
world_path="$1"
# shellcheck disable=SC1090
source "$2" # test-helpers.sh
lit() {
    bash "$world_path/lit/lit.sh" "$@";
}

set +e
output=$(lit pull 2>&1)
status_code=$?
set -e

assert_status_code 1 "$status_code" || exit 1
assert_exact_output 'This is not a Lit directory' "$output" || exit 1
