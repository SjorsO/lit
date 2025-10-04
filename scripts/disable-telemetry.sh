#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

source "$lit_base_path/scripts/helpers.sh"

if [ ! -f "$lit_base_path/data/telemetry-enabled" ]; then
    echo "Telemetry is already disabled"
    
    exit 1
fi

rm "$lit_base_path/data/telemetry-enabled"

echo "Completely disabled telemetry"
echo "No telemetry will be sent from this server (unless you run \"lit enable-telemetry\" again)"
