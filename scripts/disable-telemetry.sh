#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

source "$lit_base_path/scripts/helpers.sh"

if [ ! -f "$lit_base_path/data/telemetry-enabled" ]; then
    printf 'Telemetry is already disabled\n'

    exit 1
fi

rm "$lit_base_path/data/telemetry-enabled"

printf 'Completely disabled telemetry\n'
printf 'No telemetry will be sent for any Lit project\n'
