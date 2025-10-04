#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

source "$lit_base_path/scripts/helpers.sh"

if [ ! -f "$lit_base_path/telemetry-enabled" ]; then
    echo "Telemetry is already disabled"
    
    exit 1
fi

rm "$lit_base_path/telemetry-enabled"

echo "Disabled telemetry"
