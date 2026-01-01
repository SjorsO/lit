#!/bin/bash

assert_exact_output() {
    local expected="$1"
    local actual="$2"

    if [ "$actual" != "$expected" ]; then
        printf 'Expected output:\n%s\n\nActual output:\n%s\n' "$expected" "$actual"

        return 1
    fi
}

assert_same() {
    local expected="$1"
    local actual="$2"

    if [ "$actual" != "$expected" ]; then
        printf 'Expected "%s", got "%s"\n' "$expected" "$actual"

        return 1
    fi
}

assert_file_exists() {
    local file_path="$1"

    if [ ! -f "$file_path" ]; then
        printf 'Expected file to exist: %s\n' "$file_path"

        return 1
    fi
}

assert_directory_exists() {
    local directory_path="$1"

    if [ ! -d "$directory_path" ]; then
        printf 'Expected directory to exist: %s\n' "$directory_path"

        return 1
    fi
}

assert_file_content() {
    local file_path="$1"
    local expected="$2"

    if [ ! -f "$file_path" ]; then
        printf 'Expected file to exist: %s\n' "$file_path"

        return 1
    fi

    local actual
    actual=$(cat "$file_path")

    if [ "$actual" != "$expected" ]; then
        printf 'File %s:\nExpected:\n%s\n\nActual:\n%s\n' "$file_path" "$expected" "$actual"

        return 1
    fi
}

assert_files_match() {
    local file_path_1="$1"
    local file_path_2="$2"

    if ! diff -q "$file_path_1" "$file_path_2" > /dev/null 2>&1; then
        printf 'Files do not match:\n  %s\n  %s\n' "$file_path_1" "$file_path_2"

        return 1
    fi
}

assert_file_missing() {
    local file_path="$1"

    if [ -e "$file_path" ] || [ -L "$file_path" ]; then
        printf 'Expected file to not exist: %s\n' "$file_path"

        return 1
    fi
}

assert_symlink() {
    local file_path="$1"

    if [ ! -L "$file_path" ]; then
        printf 'Expected symlink: %s\n' "$file_path"

        return 1
    fi
}

assert_string_contains() {
    local haystack="$1"
    local needle="$2"

    if [[ "$haystack" != *"$needle"* ]]; then
        printf 'Expected string to contain "%s"\n' "$needle"

        return 1
    fi
}
