#!/bin/bash

if ! command -v curl >/dev/null 2>&1; then
    exit 0
fi

json="$(cat)"

curl --request POST https://sjorso.com/lit-telemetry \
    --header "Content-Type: application/json" \
    --data "$json" \
    --silent \
    --fail \
    --max-time 10 \
    --output /dev/null \
    2>/dev/null || true
