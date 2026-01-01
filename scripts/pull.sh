#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

if [ "$3" = "--use-commit-from-checkout" ]; then
    current_remote_commit="$4"
elif [ -n "$4" ] || ([ -n "$3" ] && [ "$3" != "--force" ]); then
    echo "usage: lit pull [--force]"

    exit 1
fi

is_forcing=$([ "$3" = "--force" ] && echo true || echo false)

source "$lit_base_path/scripts/helpers.sh"

source_type="$(get_file_value "$project_base_path/lit/source-type")"

if [ "$source_type" != "git" ] && [ "$source_type" != "bundle" ]; then
    echo "Invalid source type: \"$source_type\""

    exit 1
fi

started_at=$(date +%s)

releases_directory="$project_base_path/releases"
current_directory_path="$project_base_path/current"

# Projects previously deployed with Deployer have a "shared" directory
if [[ -d "$project_base_path/shared/storage" ]] && [[ -f "$project_base_path/shared/.env" ]]; then
    real_storage_directory_path="$project_base_path/shared/storage"
    real_env_file_path="$project_base_path/shared/.env"
else
    real_storage_directory_path="$project_base_path/storage"
    real_env_file_path="$project_base_path/.env"
fi

if [[ ! -s "$real_env_file_path" ]]; then
    touch "$real_env_file_path"

    echo 'Your ".env" file is empty, try again when you have filled it in'

    echo "[$(get_human_timestamp)] Did not deploy because the \".env\" file is empty" >> "$project_base_path/logs/lit.log"

    exit 1
fi

release_directory_created=false
release_activated=false

if [ "$source_type" = "git" ]; then
    git_repository_url="$(get_file_value "$project_base_path/lit/git-repository-url")"
    current_branch="$(get_file_value "$project_base_path/lit/current-branch")"
    current_commit="$(get_file_value "$project_base_path/lit/current-commit")"
elif [ "$source_type" = "bundle" ]; then
    bundle_url="$(get_file_value "$project_base_path/lit/bundle-url")"
fi

on_exit() {
    script_status_code=$?

    if [[ "$release_directory_created" == true && "$release_activated" == false ]]; then
        echo "Deleting new but unactivated release directory \"$new_release_directory\""

        rm -rf "$new_release_directory"
    fi

    if [[ "$release_activated" == true ]] && [ "$source_type" = "git" ]; then
        echo "[$(get_human_timestamp)] Deployed branch \"$current_branch\" (${current_commit:0:11})" >> "$project_base_path/logs/lit.log"
    elif [[ "$release_activated" == true ]] && [ "$source_type" = "bundle" ]; then
        echo "[$(get_human_timestamp)] Deployed bundle from \"$bundle_url\"" >> "$project_base_path/logs/lit.log"
    elif [[ "$release_directory_created" == true ]]; then
        echo "[$(get_human_timestamp)] Warning: Had errors, did not activate new release" >> "$project_base_path/logs/lit.log"
    fi

    if [[ "$release_activated" == true ]] && [[ "$script_status_code" -ne 0 ]]; then
        echo "[$(get_human_timestamp)] Warning: Had errors, but new release was still activated" >> "$project_base_path/logs/lit.log"

        echo ">"
        echo "> Warning: The new release has been activated!"
        echo ">"
    fi

    echo "Finished $([ "$script_status_code" -ne 0 ] && echo "with errors" || echo "successfully") (in $(( $(date +%s) - started_at )) seconds)"

    exit "$script_status_code"
}
trap on_exit EXIT TERM

for release_directory_path in "$releases_directory/"*/ ; do
    if [[ -e "$release_directory_path" ]] && ! [[ $release_directory_path =~ /[0-9]+/$ ]] ; then
       echo "The name of existing release directory \"$release_directory_path\" is not fully numeric, this should never happen"

       exit 1
    fi
done

# shellcheck disable=SC2012
# SC2012 = use find instead of ls to better handle non-alphanumeric filenames.
# Using `ls` here is safe because we just ensured all directory names are numeric.
current_release_id=$(ls "$releases_directory" | sort --numeric-sort | tail -n1) || 0;

new_release_directory="$releases_directory/$((current_release_id + 1))"

if [ "$source_type" = "git" ]; then
    # If we are pulling after a "lit checkout", then we already have the commit.
    if [[ -z "$current_remote_commit" ]]; then
        printf 'Reading "%s"... ' "$git_repository_url"

        remote_branch_info=$(git ls-remote --symref "$git_repository_url" "$current_branch")

        current_remote_commit=$(echo "$remote_branch_info" | grep -v "ref: refs/heads/" | cut -f1)

        echo ""
    fi

    if [ "$current_commit" = "$current_remote_commit" ]; then
        echo "Latest commit of \"$current_branch\" is already deployed (${current_remote_commit:0:11})"

        if [ "$is_forcing" = true ]; then
            echo 'Using "--force", redeploying...'
        else
            echo 'Run "lit pull --force" to redeploy'

            echo "[$(get_human_timestamp)] Not deploying because latest commit of \"$current_branch\" is already deployed (${current_remote_commit:0:11})" >> "$project_base_path/logs/lit.log"

            exit 0
        fi
    fi

    caching_enabled=$([ -f "$project_base_path/lit/caching-enabled" ] && echo true || echo false)
    used_cache=false

    if [ "$caching_enabled" = true ]; then
        if [ -L "$project_base_path/hooks/before-caching.sh" ]; then
            before_caching_hook_path="$(readlink -f "$project_base_path/hooks/before-caching.sh")"
        elif [ -f "$project_base_path/hooks/before-caching.sh" ]; then
            before_caching_hook_path="$project_base_path/hooks/before-caching.sh"
        else
            echo "Hook does not exist: \"$(basename "$project_base_path")/hooks/before-caching.sh\""

            before_caching_hook_path="$project_base_path/lit/caching-enabled"
        fi

        before_caching_hook_hash="$(sha1sum "$before_caching_hook_path" | cut -d' ' -f1)"

        tar_file_path=""

        if [ -f "$lit_base_path/releases/$current_remote_commit-$before_caching_hook_hash.tar.zst" ]; then
            tar_file_path="$lit_base_path/releases/$current_remote_commit-$before_caching_hook_hash.tar.zst"
        elif [ -f "$lit_base_path/releases/$current_remote_commit-$before_caching_hook_hash.tar.gz" ]; then
            tar_file_path="$lit_base_path/releases/$current_remote_commit-$before_caching_hook_hash.tar.gz"
        elif ls "$lit_base_path/releases/$current_remote_commit-"* >/dev/null 2>&1; then
            echo "Cached release found but hook changed, rebuilding..."
        fi

        if [ -n "$tar_file_path" ]; then
            printf "Reusing deployment from cache"

            current_commit="$current_remote_commit"
            used_cache=true
        else
            temp_directory_path="$lit_base_path/releases/wip_$(generate_uuid)"

            mkdir -p "$temp_directory_path"

            printf "Cloning repository... "

            git clone --branch "$current_branch" \
                --depth 100 \
                --single-branch \
                --quiet \
                "$git_repository_url" "$temp_directory_path"

            echo ""

            cd "$temp_directory_path"

            current_commit="$(git rev-parse HEAD)"

            echo "Running \"$(basename "$project_base_path")/hooks/before-caching.sh\"..."

            bash "$before_caching_hook_path" "$temp_directory_path"

            staging_directory_path="$lit_base_path/releases/$current_commit-$before_caching_hook_hash"

            if [ -d "$staging_directory_path" ]; then
                rm -rf "$staging_directory_path"
                rm -f "$staging_directory_path.tar.zst"
                rm -f "$staging_directory_path.tar.gz"
            fi

            mv "$temp_directory_path" "$staging_directory_path"

            cd "$lit_base_path/releases"

            if command -v zstd >/dev/null 2>&1; then
                printf "Caching release (zstd)... "

                tar --use-compress-program "zstd -T0 -3" -cf "$staging_directory_path.tar.zst" "$(basename "$staging_directory_path")"

                tar_file_path="$staging_directory_path.tar.zst"
            else
                printf "Caching release... "

                tar -czf "$staging_directory_path.tar.gz" "$(basename "$staging_directory_path")"

                tar_file_path="$staging_directory_path.tar.gz"
            fi

            rm -rf "$staging_directory_path"
        fi

        echo ""
        echo "Creating \"$new_release_directory\" for the new release..."

        mkdir "$new_release_directory"

        release_directory_created=true

        cd "$new_release_directory"

        printf "Extracting release... "

        tar --strip-components=1 --extract --file "$tar_file_path"

        echo ""
    else
        echo "Creating \"$new_release_directory\" for the new release..."

        mkdir "$new_release_directory"

        release_directory_created=true

        cd "$new_release_directory"

        printf "Cloning repository... "

        git clone --branch "$current_branch" \
            --depth 100 \
            --single-branch \
            --quiet \
            "$git_repository_url" "$new_release_directory"

        echo ""

        current_commit="$(git rev-parse HEAD)"
    fi
elif [ "$source_type" = "bundle" ]; then
    caching_enabled=false
    used_cache=false

    echo "Creating \"$new_release_directory\" for the new release..."

    mkdir "$new_release_directory"

    release_directory_created=true

    cd "$new_release_directory"

    printf 'Downloading bundle from "%s"... ' "$bundle_url"

    if ! curl --fail --silent --show-error --location "$bundle_url" -o "$new_release_directory/lit-bundle.tar"; then
        echo ""
        echo "Failed to download bundle from \"$bundle_url\""

        exit 1
    fi

    echo ""

    printf "Extracting bundle... "

    tar --extract --file "$new_release_directory/lit-bundle.tar"

    rm -f "$new_release_directory/lit-bundle.tar"

    echo ""
fi

echo "Creating a symlink to the storage directory"

if [[ ! -d "$real_storage_directory_path" ]]; then
    mkdir -p "$real_storage_directory_path/"{app/public,app/private,framework/{cache/data,sessions,testing,views},logs}
fi

rm -rf "$new_release_directory/storage"

ln "$(is_macos && echo "-nsf" || echo "-nsfr")" "$real_storage_directory_path" "$new_release_directory/storage"

echo "Creating a symlink to the .env file"

rm -f "$new_release_directory/.env"

ln "$(is_macos && echo "-nsf" || echo "-nsfr")" "$real_env_file_path" "$new_release_directory/.env"

if [ "$caching_enabled" = "false" ] && [ -f "$project_base_path/hooks/before-caching.sh" ]; then
    echo 'Hook "hooks/before-caching.sh" exists but will not be used because release caching is disabled'
fi

if [ -f "$project_base_path/hooks/before-activation.sh" ]; then
    hook_entry_directory=$(pwd)
    cat "$project_base_path/hooks/before-activation.sh" | bash -se -- "$project_base_path" "$new_release_directory"
    cd "$hook_entry_directory" || exit 1
else
    echo "Wanted to run \"$project_base_path/hooks/before-activation.sh\" but it does not exist"
fi

echo "Activating new release \"$new_release_directory\""

# Create a symlink to enable the release
ln "$(is_macos && echo "-nsf" || echo "-nsfr")" "$new_release_directory" "$current_directory_path"

release_activated=true

if [ "$source_type" = "git" ]; then
    echo "$current_commit" > "$project_base_path/lit/current-commit"
fi

if [ -f "$project_base_path/hooks/after-activation.sh" ]; then
    hook_entry_directory=$(pwd)
    cat "$project_base_path/hooks/after-activation.sh" | bash -se -- "$project_base_path" "$new_release_directory"
    cd "$hook_entry_directory" || exit 1
else
    echo "Wanted to run \"$project_base_path/hooks/after-activation.sh\" but it does not exist"
fi

# Only keep 2 release directories
#
# shellcheck disable=SC2012
# SC2012 = use find instead of ls to better handle non-alphanumeric filenames.
# We can safely use `ls` because we've already ensured all release directory names are numeric.
for old_release_directory in $(ls "$releases_directory" | sort --numeric-sort --reverse | tail -n+3) ; do
    printf 'Deleting old release directory "%s/%s"... ' "$releases_directory" "$old_release_directory"

    rm -rf "${releases_directory:?}/$old_release_directory"

    echo ""
done

if [ ! -f "$lit_base_path/data/telemetry-enabled" ]; then
    echo 'Not sending telemetry. You can run "lit enable-telemetry" to enable anonymouse telemetry.'

    exit 0
fi

salt=$(get_file_value "$lit_base_path/data/telemetry-salt")

if [ "$source_type" = "git" ]; then
    deployed_identifier="$(echo "$salt$current_commit" | shasum | cut -d' ' -f1)"
elif [ "$source_type" = "bundle" ]; then
    deployed_identifier="$(echo "$salt$bundle_url" | shasum | cut -d' ' -f1)"
fi

bash "$lit_base_path/scripts/telemetry.sh" <<EOF &
{
    "action": "pull",
    "os": "${OSTYPE:-not set}",
    "lit_version": "$(get_file_value "$lit_base_path/data/lit-version")",
    "lit_installation_id": "$(get_file_value "$lit_base_path/data/installation-id")",
    "lit_project_id": "$(echo "$salt$project_base_path" | shasum | cut -d' ' -f1)",
    "source_type": "$source_type",
    "caching_enabled": $caching_enabled,
    "used_cache": $used_cache,
    "deployed_identifier": "$deployed_identifier"
}
EOF
