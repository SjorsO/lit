#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

source "$lit_base_path/scripts/helpers.sh"

if [ -f "$lit_base_path/data/telemetry-enabled" ]; then
    printf 'Telemetry is already enabled\n'

    exit 1
fi

touch "$lit_base_path/data/telemetry-enabled"

printf 'Enabled anonymous telemetry\n'
