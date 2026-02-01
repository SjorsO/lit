current_release_directory_path="$project_base_path/current"

if [ ! -L "$current_release_directory_path" ]; then
    printf 'Unable to %s, can not find the current release.\n' "$action_label"

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

if [ -z "$previous_release_directory_path" ] && [ "${not_if_first_release:-}" = "true" ]; then
    printf 'Not flushing OPcache because this appears to be the first deployment\n'

    exit 0
fi

if [ ! -f "$project_base_path/.env" ]; then
    printf 'Unable to %s, no .env file found\n' "$action_label"

    exit 1
fi

app_url=$(grep -E "^APP_URL=" "$project_base_path/.env" | tr -d '\r' | cut -d'=' -f2- | sed 's/^["'"'"']//;s/["'"'"']$//' | sed 's:/*$::')

if [ -z "$app_url" ]; then
    printf 'Unable to %s, APP_URL not found in .env file\n' "$action_label"

    exit 1
fi

script_file_name="lit-$(uuidgen | cut -c25-36 | tr '[:upper:]' '[:lower:]').php"
script_file_path="$current_release_directory_path/public/$script_file_name"

previous_script_file_path=""
if [ -n "$previous_release_directory_path" ]; then
    previous_script_file_path="$previous_release_directory_path/public/$script_file_name"
fi

has_completed=false

on_exit() {
    exit_status_code=$?

    if [[ -f "$script_file_path" ]]; then
        rm "$script_file_path"
    fi

    if [[ -n "$previous_script_file_path" && -f "$previous_script_file_path" ]]; then
        rm "$previous_script_file_path"
    fi

    if [[ "$has_completed" == false ]]; then
        printf 'Failed to %s. The APP_URL in your .env file is set to "%s", is this correct?\n' "$action_label" "$app_url"
    fi

    exit "$exit_status_code"
}
trap on_exit INT EXIT TERM

cp "$php_source_file" "$script_file_path"

if [ -n "$previous_script_file_path" ]; then
    cp "$php_source_file" "$previous_script_file_path"
fi

script_url="$app_url/$script_file_name"

if [ -n "${query_string:-}" ]; then
    script_url="$script_url?$query_string"
fi

printf 'Calling "%s" to %s.\n' "$app_url" "$action_label"

curl "$script_url" \
    --silent \
    --show-error \
    --fail \
    --retry 3 \
    --max-time 5 \
    --retry-max-time 20

has_completed=true
