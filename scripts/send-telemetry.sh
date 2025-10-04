#!/bin/bash

if ! command -v curl >/dev/null 2>&1; then
    exit 0
fi

curl -X POST https://sjorso.com/lit-telemetry \
    -H "Content-Type: application/json" \
    -d "$1" \
    --silent \
    --fail \
    --output /dev/null \
    2>/dev/null || true
