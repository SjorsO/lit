#!/bin/bash

_assert_failed() {
    local caller_info
    caller_info=$(caller 1)
    local line_number file_name
    line_number=$(echo "$caller_info" | cut -d' ' -f1)
    file_name=$(basename "$(echo "$caller_info" | cut -d' ' -f3)")

    printf '[%s:%s] ' "$file_name" "$line_number"
}

assert_exact_output() {
    local expected="$1"
    local actual="$2"

    if [ "$actual" != "$expected" ]; then
        _assert_failed
        printf 'Expected output:\n%s\n\nActual output:\n%s\n' "$expected" "$actual"

        return 1
    fi
}

assert_same() {
    local expected="$1"
    local actual="$2"

    if [ "$actual" != "$expected" ]; then
        _assert_failed
        printf 'Expected "%s", got "%s"\n' "$expected" "$actual"

        return 1
    fi
}

assert_file_exists() {
    local file_path="$1"

    if [ ! -f "$file_path" ]; then
        _assert_failed
        printf 'Expected file to exist: %s\n' "$file_path"

        return 1
    fi
}

assert_directory_exists() {
    local directory_path="$1"

    if [ ! -d "$directory_path" ]; then
        _assert_failed
        printf 'Expected directory to exist: %s\n' "$directory_path"

        return 1
    fi
}

assert_file_content() {
    local file_path="$1"
    local expected="$2"

    if [ ! -f "$file_path" ]; then
        _assert_failed
        printf 'Expected file to exist: %s\n' "$file_path"

        return 1
    fi

    local actual
    actual=$(cat "$file_path")

    if [ "$actual" != "$expected" ]; then
        _assert_failed
        printf 'File %s:\nExpected:\n%s\n\nActual:\n%s\n' "$file_path" "$expected" "$actual"

        return 1
    fi
}

assert_files_match() {
    local file_path_1="$1"
    local file_path_2="$2"

    if ! diff -q "$file_path_1" "$file_path_2" > /dev/null 2>&1; then
        _assert_failed
        printf 'Files do not match:\n  %s\n  %s\n' "$file_path_1" "$file_path_2"

        return 1
    fi
}

assert_file_missing() {
    local file_path="$1"

    if [ -e "$file_path" ] || [ -L "$file_path" ]; then
        _assert_failed
        printf 'Expected file to not exist: %s\n' "$file_path"

        return 1
    fi
}

assert_symlink() {
    local file_path="$1"

    if [ ! -L "$file_path" ]; then
        _assert_failed
        printf 'Expected symlink: %s\n' "$file_path"

        return 1
    fi
}

assert_string_contains() {
    local haystack="$1"
    local needle="$2"

    if [[ "$haystack" != *"$needle"* ]]; then
        _assert_failed
        printf 'Expected string to contain "%s"\n' "$needle"

        return 1
    fi
}

assert_string_not_contains() {
    local haystack="$1"
    local needle="$2"

    if [[ "$haystack" == *"$needle"* ]]; then
        _assert_failed
        printf 'Expected string to NOT contain "%s"\n' "$needle"

        return 1
    fi
}

assert_string_matches() {
    local haystack="$1"
    local pattern="$2"

    if ! [[ "$haystack" =~ $pattern ]]; then
        _assert_failed
        printf 'Expected string to match pattern "%s"\n' "$pattern"

        return 1
    fi
}

is_macos() {
    [[ "$OSTYPE" == "darwin"* ]]
}
