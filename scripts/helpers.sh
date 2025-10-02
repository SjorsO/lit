#!/bin/bash

show_spinner() {
    local pid=$1
    local message=$2
    local delay=0.1
    local spinstr='|/-\'
    local should_clear_previous_render=false
    
    while [ "$(ps a | awk '{print $1}' | grep $pid)" ]; do
        
        if [ "$should_clear_previous_render" = true ]; then
            printf "\033[2K\r"
        fi

        should_clear_previous_render=true

        local temp=${spinstr#?}
        printf " [%c] $message..." "$spinstr"
        local spinstr=$temp${spinstr%"$temp"}
        sleep $delay        
    done    

    printf "\033[2K\r"

    printf " [✓] $message... Done!\n" "$spinstr"
}
