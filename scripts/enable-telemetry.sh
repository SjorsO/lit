#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

source "$lit_base_path/scripts/helpers.sh"

if [ -f "$project_base_path/lit/telemetry-enabled" ]; then
    echo "Telemetry is already enabled"
    
    exit 1
fi

generate_uuid > "$project_base_path/lit/telemetry-enabled"

echo "Enabled anonymous telemetry"
