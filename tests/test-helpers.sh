#!/bin/bash

assert_exact_output() {
    local expected="$1"
    local actual="$2"

    if [ "$actual" != "$expected" ]; then
        printf 'Expected output:\n%s\n\nActual output:\n%s\n' "$expected" "$actual"

        return 1
    fi
}

assert_status_code() {
    local expected="$1"
    local actual="$2"

    if [ "$actual" != "$expected" ]; then
        printf 'Expected status code %s, got %s\n' "$expected" "$actual"

        return 1
    fi
}
