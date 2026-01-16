#!/bin/bash

set -e

lit_base_path="$1"

source "$lit_base_path/scripts/helpers.sh"

if [ ! -d "$lit_base_path/data" ]; then
    mkdir "$lit_base_path/data"
fi

on_exit() {
    script_status_code=$?

    # Restore cursor (only if we have a terminal)
    [ -t 1 ] && tput cnorm 2>/dev/null || true

    exit "$script_status_code"
}
trap on_exit EXIT

# Hide cursor (only if we have a terminal)
[ -t 1 ] && tput civis 2>/dev/null || true

yes_no_menu() {
    local current=0
    local key=""

    while true; do
        printf "\033[1A\r\033[K" >&2

        if [ $current -eq 0 ]; then
            printf "  \033[32m[Yes]\033[0m    [No]\n" >&2
        else
            printf "  [Yes]    \033[32m[No]\033[0m\n" >&2
        fi

        read -rsn1 key || {
            exit 130
        }

        case "$key" in
            $'\e')
                read -rsn2 key
                case "$key" in
                    '[C')
                        current=$(( (current + 1) % 2 ))
                        ;;
                    '[D')
                        current=$(( (current - 1 + 2) % 2 ))
                        ;;
                esac
                ;;
            '')
                case $current in
                    0) echo "y"; return ;;
                    1) echo "n"; return ;;
                esac
                ;;
            'y'|'Y')
                echo "y"; return
                ;;
            'n'|'N')
                echo "n"; return
                ;;
        esac
    done
}

alias_files=(
    "$HOME/.bash_aliases"
    "$HOME/.zsh_aliases"
    "$HOME/.zshrc"
    "$HOME/.bashrc"
    "$HOME/.bash_profile"
    "$HOME/.profile"
)

alias_file=""
alias_created=false

for file in "${alias_files[@]}"; do
    if [ -f "$file" ]; then
        alias_file="$file"
        break
    fi
done

echo "                           ┌──────────────────┐"
echo "╭──────────────────────────┤  Welcome to Lit  ├──────────────────────────╮"
echo "                           └──────────────────┘"

if [ -z "$alias_file" ]; then
    echo "  Normally Lit would ask you if you want to add an alias, but Lit"
    echo "  can't find the file to put the alias in."
    echo ""
    echo "  You can add the following alias manually:"
    echo ""
    echo "    alias lit=\"$lit_base_path/lit.sh\""

    echo ""
    echo "├────────────────────────────────────────────────────────────────────────┤"
    echo ""
elif grep -q "lit.sh" "$alias_file" 2>/dev/null; then
    :
else
    echo "  Would you like to add an alias for Lit?"
    echo ""
    echo "  File:"
    echo "    $alias_file"
    echo ""
    echo "  Alias:"
    echo "    alias lit=\"$lit_base_path/lit.sh\""
    echo ""
    echo "  Add alias?"

    result=$(yes_no_menu)

    if [[ "$result" == "y" ]]; then
        echo ""
        echo "" >> "$alias_file"
        echo "alias lit=\"$lit_base_path/lit.sh\"" >> "$alias_file"
        echo "  Alias added."
        alias_created=true
    else
        echo ""
        echo "  Not adding alias."
    fi

    echo ""
    echo "├────────────────────────────────────────────────────────────────────────┤"
    echo ""
fi

echo "  You can help Lit by enabling telemetry."
echo "  Telemetry is fully anonymous with no performance impact."
echo ""
echo "  Enable anonymous telemetry?"
echo ""
echo ""

result=$(yes_no_menu)

if [[ "$result" == "y" ]]; then
    touch "$lit_base_path/data/telemetry-enabled"
    echo ""
    echo "  Telemetry enabled."
else
    rm -f "$lit_base_path/data/telemetry-enabled"
    echo ""
    echo "  Not enabling telemetry."
fi

echo ""
echo "├────────────────────────────────────────────────────────────────────────┤"
echo ""
echo "  All done, you're ready to use Lit."
echo ""
echo "╰────────────────────────────────────────────────────────────────────────╯"
echo ""

uuidgen | tr '[:upper:]' '[:lower:]' > "$lit_base_path/data/installation-id"

if [ "$alias_created" = true ]; then
    exit 100
fi
