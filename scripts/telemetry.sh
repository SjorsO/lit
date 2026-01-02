#!/bin/bash

json="$(cat)"

if echo "$json" | grep -q '"lit_installation_id": "testing"'; then
    echo "$json" >> /tmp/lit-telemetry

    exit 0
fi

if ! command -v curl >/dev/null 2>&1; then
    exit 0
fi

curl --request POST https://sjorso.com/lit-telemetry \
    --header "Content-Type: application/json" \
    --data "$json" \
    --silent \
    --fail \
    --max-time 10 \
    --output /dev/null \
    2>/dev/null || true
