#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

source "$lit_base_path/scripts/helpers.sh"

git_repository_url="$(get_file_value "$project_base_path/lit/git-repository-url")"
current_branch="$(get_file_value "$project_base_path/lit/current-branch")"
current_commit="$(get_file_value "$project_base_path/lit/current-commit")"

reusing_status=$([ -f "$project_base_path/lit/reusing-enabled" ] && echo "enabled" || echo "disabled")
telemetry_status=$([ -f "$lit_base_path/data/telemetry-enabled" ] && echo "enabled" || echo "disabled")

line1=" Git repository url: $git_repository_url"
line2="     Current branch: $current_branch"
line3="     Current commit: $current_commit"
line4="   Reusing releases: $reusing_status"
line5="Anonymous telemetry: $telemetry_status"

for line in "$line1" "$line2" "$line3" "$line4" "$line5"; do
    if [ ${#line} -gt ${max_length:-0} ]; then
        max_length=${#line}
    fi
done

box_width=$((max_length + 2))
horizontal_line=$(printf "─%.0s" $(seq 1 $box_width))

echo "╭${horizontal_line}╮"
printf "│ %-${max_length}s │\n" "$line1"
printf "│ %-${max_length}s │\n" "$line2"
printf "│ %-${max_length}s │\n" "$line3"
printf "│ %-${max_length}s │\n" "$line4"
printf "│ %-${max_length}s │\n" "$line5"
echo "╰${horizontal_line}╯"
