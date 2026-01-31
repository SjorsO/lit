set -e

project_base_path="$1"
json_flag="$2"

current_release_directory_path="$project_base_path/current"

if [ ! -L "$current_release_directory_path" ]; then
    printf 'Unable to get opcache status, can not find the current release.\n'

    exit 1
fi

if [ ! -f "$project_base_path/.env" ]; then
    printf 'Unable to get opcache status, no .env file found\n'

    exit 1
fi

app_url=$(grep -E "^APP_URL=" "$project_base_path/.env" | tr -d '\r' | cut -d'=' -f2- | sed 's/^["'"'"']//;s/["'"'"']$//' | sed 's:/*$::')

if [ -z "$app_url" ]; then
    printf 'Unable to get opcache status, APP_URL not found in .env file\n'

    exit 1
fi

opcache_status_script_file_name="lit-opcache-status-$(uuidgen | cut -c1-8 | tr '[:upper:]' '[:lower:]').php"

opcache_status_script_file_path="$current_release_directory_path/public/$opcache_status_script_file_name"

has_fetched_opcache_status=false

on_exit() {
    script_status_code=$?

    if [[ -f "$opcache_status_script_file_path" ]]; then
        rm "$opcache_status_script_file_path"
    fi

    if [[ "$has_fetched_opcache_status" == false ]]; then
        printf 'Failed to get OPCache status. The APP_URL in your .env file is set to "%s", is this correct?\n' "$app_url"
    fi

    exit "$script_status_code"
}
trap on_exit INT EXIT TERM

cp "$(dirname "$0")/opcache-status.php" "$opcache_status_script_file_path"

opcache_status_url="$app_url/$opcache_status_script_file_name"

if [ "$json_flag" = "--json" ]; then
    opcache_status_url="$opcache_status_url?json"
fi

printf 'Calling "%s" to get OPCache status.\n' "$app_url"

curl "$opcache_status_url" \
    --silent \
    --show-error \
    --fail \
    --retry 2 \
    --max-time 5

has_fetched_opcache_status=true
