set -e

project_base_path="$1"

current_release_directory_path="$project_base_path/current"

if [ ! -L "$current_release_directory_path" ]; then
    printf 'Unable to flush opcache, can not find the current release.\n'

    exit 1
fi

current_release_id=$(basename "$(readlink "$current_release_directory_path")")

previous_release_directory_path=""

for release_id in $(ls "$project_base_path/releases" 2>/dev/null | sort --numeric-sort --reverse); do
    if [ "$release_id" != "$current_release_id" ]; then
        previous_release_directory_path="$project_base_path/releases/$release_id"

        break
    fi
done

if [ -z "$previous_release_directory_path" ]; then
    printf 'Not flushing opcache because this appears to be the first deployment\n'

    exit 0
fi

if [ ! -f "$project_base_path/.env" ]; then
    printf 'Unable to flush opcache, no .env file found\n'

    exit 1
fi

app_url=$(grep -E "^APP_URL=" "$project_base_path/.env" | tr -d '\r' | cut -d'=' -f2- | sed 's/^["'"'"']//;s/["'"'"']$//' | sed 's:/*$::')

if [ -z "$app_url" ]; then
    printf 'Unable to flush opcache, APP_URL not found in .env file\n'

    exit 1
fi

has_flushed_opcache=false

opcache_reset_script_file_name="lit-flush-opcache-$(uuidgen | cut -c1-8 | tr '[:upper:]' '[:lower:]').php"

# We flush OPCache right after we create the symlink for our new release. We have to create the file
# twice because Nginx needs a brief moment to realise that the release directory has been changed.
opcache_reset_script_file_path_1="$current_release_directory_path/public/$opcache_reset_script_file_name"
opcache_reset_script_file_path_2="$previous_release_directory_path/public/$opcache_reset_script_file_name"

on_exit() {
    script_status_code=$?

    if [[ -f "$opcache_reset_script_file_path_1" ]]; then
        rm "$opcache_reset_script_file_path_1"
    fi

    if [[ -f "$opcache_reset_script_file_path_2" ]]; then
        rm "$opcache_reset_script_file_path_2"
    fi

    if [[ "$has_flushed_opcache" == false ]]; then
        printf 'Failed to flush OPCache. The APP_URL in your .env file is set to "%s", is this correct?\n' "$app_url"
    fi

    exit "$script_status_code"
}
trap on_exit INT EXIT TERM

cat << PHP > "$opcache_reset_script_file_path_1"
<?php

echo function_exists('opcache_reset') && opcache_reset()
    ? "OPCache flushed successfully.\n"
    : "OPCache is not enabled.\n";
PHP

cp "$opcache_reset_script_file_path_1" "$opcache_reset_script_file_path_2"

printf 'Pinging "%s" to flush OPCache.\n' "$app_url"

curl "$app_url/$opcache_reset_script_file_name" \
    --silent \
    --show-error \
    --fail \
    --retry 3 \
    --max-time 5 \
    --retry-max-time 60

has_flushed_opcache=true
