#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

if [ "$3" = "--use-commit-from-checkout" ]; then
    current_remote_commit="$4"
elif [ -n "$4" ] || ([ -n "$3" ] && [ "$3" != "--force" ]); then
    printf 'usage: lit deploy [--force]\n'

    exit 1
fi

is_forcing=$([ "$3" = "--force" ] && echo true || echo false)

source "$lit_base_path/scripts/helpers.sh"

source_type="$(get_file_value "$project_base_path/lit/source-type")"

if [ "$source_type" != "git" ] && [ "$source_type" != "bundle" ]; then
    printf 'Invalid source type: "%s"\n' "$source_type"

    exit 1
fi

started_at=$(date +%s)

releases_directory="$project_base_path/releases"
current_directory_path="$project_base_path/current"

real_storage_directory_path="$project_base_path/storage"
real_env_file_path="$project_base_path/.env"

if [[ ! -s "$real_env_file_path" ]]; then
    touch "$real_env_file_path"

    printf 'Your ".env" file is empty, try again when you have filled it in\n'

    echo "[$(get_human_timestamp)] Did not deploy because the \".env\" file is empty" >> "$project_base_path/logs/lit.log"

    exit 1
fi

release_directory_created=false
was_released=false

if [ "$source_type" = "git" ]; then
    git_repository_url="$(get_file_value "$project_base_path/lit/git-repository-url")"
    current_branch="$(get_file_value "$project_base_path/lit/current-branch")"
    current_commit="$(get_file_value "$project_base_path/lit/current-commit")"
elif [ "$source_type" = "bundle" ]; then
    bundle_url="$(get_file_value "$project_base_path/lit/bundle-url")"
    current_bundle_hash="$(get_file_value "$project_base_path/lit/current-bundle-hash")"
fi

on_exit() {
    script_status_code=$?

    if [[ "$release_directory_created" == true && "$was_released" == false ]]; then
        printf 'Deleting new but unreleased release directory "%s"\n' "$new_release_directory"

        rm -rf "$new_release_directory"

        cd "$project_base_path"
    fi

    # Clean up temp directories from cache building if they still exist
    if [[ -n "$temp_directory_path" && -d "$temp_directory_path" ]]; then
        rm -rf "$temp_directory_path"
    fi

    if [[ -n "$staging_directory_path" && -d "$staging_directory_path" ]]; then
        rm -rf "$staging_directory_path"
    fi

    if [[ "$was_released" == true ]] && [ "$source_type" = "git" ]; then
        echo "[$(get_human_timestamp)] Deployed branch \"$current_branch\" (commit: ${current_commit:0:11})" >> "$project_base_path/logs/lit.log"
    elif [[ "$was_released" == true ]] && [ "$source_type" = "bundle" ]; then
        echo "[$(get_human_timestamp)] Deployed bundle (hash: ${new_bundle_hash:0:11})" >> "$project_base_path/logs/lit.log"
    elif [[ "$release_directory_created" == true ]]; then
        echo "[$(get_human_timestamp)] Warning: Had errors, new deployment was not released" >> "$project_base_path/logs/lit.log"
    elif [[ "$script_status_code" -ne 0 ]] && [[ "$was_released" == false ]]; then
        echo "[$(get_human_timestamp)] Deploy failed, new deployment was not released" >> "$project_base_path/logs/lit.log"
    fi

    if [[ "$was_released" == true ]] && [[ "$script_status_code" -ne 0 ]]; then
        echo "[$(get_human_timestamp)] Warning: Had errors, but new deployment was still released" >> "$project_base_path/logs/lit.log"

        printf '>\n'
        printf '> Warning: The new deployment was still released!\n'
        printf '>\n'
    fi

    if [[ "$script_status_code" -ne 0 ]]; then
        if [ -f "$project_base_path/hooks/on-failure.sh" ]; then
            if ! cat "$project_base_path/hooks/on-failure.sh" | bash -se -- "$project_base_path" "$was_released"; then
                printf 'The on-failure hook failed\n'
                echo "[$(get_human_timestamp)] The on-failure hook failed" >> "$project_base_path/logs/lit.log"
            fi
        else
            printf 'Wanted to run "%s/hooks/on-failure.sh" but it does not exist\n' "$project_base_path"
        fi
    fi

    printf 'Finished %s (in %s seconds)\n' "$([ "$script_status_code" -ne 0 ] && echo "with errors" || echo "successfully")" "$(( $(date +%s) - started_at ))"

    exit "$script_status_code"
}
trap on_exit EXIT TERM

for release_directory_path in "$releases_directory/"*/ ; do
    if [[ -e "$release_directory_path" ]] && ! [[ $release_directory_path =~ /[0-9]+/$ ]] ; then
       printf 'The name of existing release directory "%s" is not fully numeric, this should never happen\n' "$release_directory_path"

       exit 1
    fi
done

# shellcheck disable=SC2012
# SC2012 = use find instead of ls to better handle non-alphanumeric filenames.
# Using `ls` here is safe because we just ensured all directory names are numeric.
current_release_id=$(ls "$releases_directory" | sort --numeric-sort | tail -n1) || 0;

new_release_directory="$releases_directory/$((current_release_id + 1))"

if [ "$source_type" = "git" ]; then
    # If we are deploying after a "lit checkout", then we already have the commit.
    if [[ -z "$current_remote_commit" ]]; then
        printf 'Reading branch "%s" of "%s"... ' "$current_branch" "$git_repository_url"

        remote_branch_info=$(git ls-remote --symref "$git_repository_url" "$current_branch")

        current_remote_commit=$(echo "$remote_branch_info" | grep -v "ref: refs/heads/" | cut -f1)

        printf '\n'
    fi

    if [ "$current_commit" = "$current_remote_commit" ]; then
        printf 'Latest commit of "%s" is already deployed (%s)\n' "$current_branch" "${current_remote_commit:0:11}"

        if [ "$is_forcing" = true ]; then
            printf 'Using "--force", redeploying...\n'
        else
            printf 'Run "lit deploy --force" to redeploy\n'

            echo "[$(get_human_timestamp)] Not deploying because latest commit of \"$current_branch\" is already deployed (${current_remote_commit:0:11})" >> "$project_base_path/logs/lit.log"

            exit 0
        fi
    fi

    caching_enabled=$([ -f "$project_base_path/lit/caching-enabled" ] && echo true || echo false)
    used_cache=false

    if [ "$caching_enabled" = true ]; then
        if [ -L "$project_base_path/hooks/before-caching.sh" ]; then
            before_caching_hook_path="$(readlink -f "$project_base_path/hooks/before-caching.sh")"
            before_caching_hook_hash="$(sha1sum "$before_caching_hook_path" | cut -d' ' -f1)"
        elif [ -f "$project_base_path/hooks/before-caching.sh" ]; then
            before_caching_hook_path="$project_base_path/hooks/before-caching.sh"
            before_caching_hook_hash="$(sha1sum "$before_caching_hook_path" | cut -d' ' -f1)"
        else
            before_caching_hook_path=""
            before_caching_hook_hash="no-hook"
        fi

        tar_file_path=""

        if [ -f "$lit_base_path/releases/$current_remote_commit-$before_caching_hook_hash.tar.zst" ]; then
            tar_file_path="$lit_base_path/releases/$current_remote_commit-$before_caching_hook_hash.tar.zst"
        elif [ -f "$lit_base_path/releases/$current_remote_commit-$before_caching_hook_hash.tar.gz" ]; then
            tar_file_path="$lit_base_path/releases/$current_remote_commit-$before_caching_hook_hash.tar.gz"
        elif ls "$lit_base_path/releases/$current_remote_commit-"* >/dev/null 2>&1; then
            printf 'Cached release found but hook changed, rebuilding...\n'
        fi

        if [ -n "$tar_file_path" ]; then
            printf 'Reusing deployment from cache'

            # Update timestamp so this cache entry isn't pruned
            touch "$tar_file_path"

            current_commit="$current_remote_commit"
            used_cache=true
        else
            temp_directory_path="$lit_base_path/releases/wip_$(uuidgen | tr '[:upper:]' '[:lower:]')"

            mkdir -p "$temp_directory_path"

            printf 'Cloning repository... '

            git clone --branch "$current_branch" \
                --depth 100 \
                --single-branch \
                --quiet \
                "$git_repository_url" "$temp_directory_path"

            printf '\n'

            cd "$temp_directory_path"

            current_commit="$(git rev-parse HEAD)"

            if [ -n "$before_caching_hook_path" ]; then
                printf 'Running "%s/hooks/before-caching.sh"...\n' "$(basename "$project_base_path")"

                bash "$before_caching_hook_path" "$temp_directory_path" "$project_base_path" "$lit_base_path"
            else
                printf 'Wanted to run "%s/hooks/before-caching.sh" but it does not exist\n' "$project_base_path"
            fi

            staging_directory_path="$lit_base_path/releases/$current_commit-$before_caching_hook_hash"

            if [ -d "$staging_directory_path" ]; then
                rm -rf "$staging_directory_path"
                rm -f "$staging_directory_path.tar.zst"
                rm -f "$staging_directory_path.tar.gz"
            fi

            mv "$temp_directory_path" "$staging_directory_path"

            cd "$lit_base_path/releases"

            if command -v zstd >/dev/null 2>&1; then
                printf 'Caching release... '

                tar --use-compress-program "zstd -T0 -3" -cf "$staging_directory_path.tar.zst" "$(basename "$staging_directory_path")"

                tar_file_path="$staging_directory_path.tar.zst"
            else
                printf 'Caching release... (tip: install "zstd" for faster caching)'

                tar -czf "$staging_directory_path.tar.gz" "$(basename "$staging_directory_path")"

                tar_file_path="$staging_directory_path.tar.gz"
            fi

            rm -rf "$staging_directory_path"
        fi

        printf '\n'
        printf 'Creating "%s" for the new release...\n' "$new_release_directory"

        mkdir "$new_release_directory"

        release_directory_created=true

        cd "$new_release_directory"

        printf 'Extracting release... '

        tar --strip-components=1 --extract --file "$tar_file_path"

        printf '\n'
    else
        printf 'Creating "%s" for the new release...\n' "$new_release_directory"

        mkdir "$new_release_directory"

        release_directory_created=true

        cd "$new_release_directory"

        printf 'Cloning repository... '

        git clone --branch "$current_branch" \
            --depth 100 \
            --single-branch \
            --quiet \
            "$git_repository_url" "$new_release_directory"

        printf '\n'

        current_commit="$(git rev-parse HEAD)"
    fi
elif [ "$source_type" = "bundle" ]; then
    caching_enabled=false
    used_cache=false

    # Caching is not supported for bundles. Silently delete this file if it exists to prevent any
    # confusion in the status command or in telemetry.
    rm -f "$project_base_path/lit/caching-enabled"

    bundle_hash_url="${bundle_url}.hash"
    remote_bundle_hash_from_hash_file=""

    if [ "$is_forcing" = false ]; then
        # To avoid downloading the full bundle, download just a file containing the bundle hash.
        printf 'Checking bundle version from "%s"... ' "$bundle_hash_url"

        set +e
        curl_output=$(curl --fail --silent --show-error --location "$bundle_hash_url" 2>&1)
        curl_exit_code=$?
        set -e

        if [ $curl_exit_code -eq 0 ]; then
            remote_bundle_hash_from_hash_file=$(echo "$curl_output" | tr -d '[:space:]')

            printf '\n'

            if ! [[ "$remote_bundle_hash_from_hash_file" =~ ^[a-fA-F0-9]{40}$ ]]; then
                printf 'Warning: "%s" does not contain a valid SHA1 hash"\n' "$bundle_hash_url"
                printf 'Hash file contents: %s\n' "$curl_output"
                remote_bundle_hash_from_hash_file=""
            elif [ "$current_bundle_hash" = "$remote_bundle_hash_from_hash_file" ]; then
                printf 'Bundle is already deployed (hash: %s)\n' "${remote_bundle_hash_from_hash_file:0:11}"
                printf 'Run "lit deploy --force" to redeploy\n'

                echo "[$(get_human_timestamp)] Not deploying because same bundle version is already deployed (hash: ${remote_bundle_hash_from_hash_file:0:11})" >> "$project_base_path/logs/lit.log"

                exit 0
            fi
        else
            printf '\nWarning: %s\n' "$curl_output"
        fi
    fi

    printf 'Downloading bundle from "%s"... ' "$bundle_url"

    temp_bundle_path="$project_base_path/lit/bundle-for-current-deployment.tar"

    rm -f "$temp_bundle_path"

    if ! curl --fail --silent --show-error --location "$bundle_url" -o "$temp_bundle_path"; then
        printf '\n'
        printf 'Failed to download bundle from "%s"\n' "$bundle_url"

        echo "[$(get_human_timestamp)] Failed to download bundle from \"$bundle_url\"" >> "$project_base_path/logs/lit.log"

        rm -f "$temp_bundle_path"

        exit 1
    fi

    if is_macos; then
        bundle_size_in_bytes=$(stat -f%z "$temp_bundle_path")
    else
        bundle_size_in_bytes=$(stat -c%s "$temp_bundle_path")
    fi

    printf '(%s MB)\n' "$((bundle_size_in_bytes / 1048576))"

    new_bundle_hash="$(shasum "$temp_bundle_path" | cut -d' ' -f1)"

    if [ -n "$remote_bundle_hash_from_hash_file" ] && [ "$remote_bundle_hash_from_hash_file" != "$new_bundle_hash" ]; then
        printf 'Warning: the hash from "%s" does not match the actual hash from "%s"\n' "$bundle_hash_url" "$bundle_url"
    fi

    if [ "$current_bundle_hash" = "$new_bundle_hash" ]; then
        printf 'Bundle is already deployed (hash: %s)\n' "${new_bundle_hash:0:11}"

        if [ "$is_forcing" = true ]; then
            printf 'Using "--force", redeploying...\n'
        else
            rm -f "$temp_bundle_path"

            printf 'Run "lit deploy --force" to redeploy\n'

            echo "[$(get_human_timestamp)] Not deploying because same bundle version is already deployed (hash: ${new_bundle_hash:0:11})" >> "$project_base_path/logs/lit.log"

            exit 0
        fi
    fi

    printf 'Creating "%s" for the new release...\n' "$new_release_directory"

    mkdir "$new_release_directory"

    release_directory_created=true

    cd "$new_release_directory"

    mv "$temp_bundle_path" "$new_release_directory/lit-bundle.tar"

    printf 'Extracting bundle... '

    tar --extract --file "$new_release_directory/lit-bundle.tar"

    rm -f "$new_release_directory/lit-bundle.tar"

    printf '\n'
fi

printf 'Creating a symlink to the storage directory\n'

if [[ ! -d "$real_storage_directory_path" ]]; then
    mkdir -p "$real_storage_directory_path/"{app/public,app/private,framework/{cache/data,sessions,testing,views},logs}
fi

rm -rf "$new_release_directory/storage"

ln "$(is_macos && echo "-nsf" || echo "-nsfr")" "$real_storage_directory_path" "$new_release_directory/storage"

printf 'Creating a symlink to the .env file\n'

rm -f "$new_release_directory/.env"

ln "$(is_macos && echo "-nsf" || echo "-nsfr")" "$real_env_file_path" "$new_release_directory/.env"

if [ "$caching_enabled" = "false" ] && [ -f "$project_base_path/hooks/before-caching.sh" ]; then
    printf 'Hook "hooks/before-caching.sh" exists but will not be used because release caching is disabled\n'
fi

if [ -f "$project_base_path/hooks/before-release.sh" ]; then
    hook_entry_directory=$(pwd)
    cat "$project_base_path/hooks/before-release.sh" | bash -se -- "$project_base_path" "$new_release_directory" "$lit_base_path"
    cd "$hook_entry_directory" || exit 1
else
    printf 'Wanted to run "%s/hooks/before-release.sh" but it does not exist\n' "$project_base_path"
fi

printf 'Releasing the new deployment "%s"\n' "$new_release_directory"

# Create a symlink to enable the release
ln "$(is_macos && echo "-nsf" || echo "-nsfr")" "$new_release_directory" "$current_directory_path"

was_released=true

if [ "$source_type" = "git" ]; then
    echo "$current_commit" > "$project_base_path/lit/current-commit"
elif [ "$source_type" = "bundle" ]; then
    echo "$new_bundle_hash" > "$project_base_path/lit/current-bundle-hash"
fi

if [ -f "$project_base_path/hooks/after-release.sh" ]; then
    hook_entry_directory=$(pwd)
    cat "$project_base_path/hooks/after-release.sh" | bash -se -- "$project_base_path" "$new_release_directory" "$lit_base_path"
    cd "$hook_entry_directory" || exit 1
else
    printf 'Wanted to run "%s/hooks/after-release.sh" but it does not exist\n' "$project_base_path"
fi

# Only keep 2 release directories
#
# shellcheck disable=SC2012
# SC2012 = use find instead of ls to better handle non-alphanumeric filenames.
# We can safely use `ls` because we've already ensured all release directory names are numeric.
for old_release_directory in $(ls "$releases_directory" | sort --numeric-sort --reverse | tail -n+3) ; do
    printf 'Deleting old release directory "%s/%s"... ' "$releases_directory" "$old_release_directory"

    rm -rf "${releases_directory:?}/$old_release_directory"

    printf '\n'
done

# Prune cached releases older than 7 days
if [ -n "$lit_base_path" ] && [ -d "$lit_base_path/releases" ]; then
    find "$lit_base_path/releases" -maxdepth 1 -type f -name "*.tar.zst" -mtime +7 -delete 2>/dev/null
    find "$lit_base_path/releases" -maxdepth 1 -type f -name "*.tar.gz" -mtime +7 -delete 2>/dev/null
fi

if [ ! -f "$lit_base_path/data/telemetry-enabled" ]; then
    exit 0
fi

salt=$(get_file_value "$lit_base_path/data/telemetry-salt")

if [ "$source_type" = "git" ]; then
    deployed_identifier="$(echo "$salt$current_commit" | shasum | cut -d' ' -f1)"
elif [ "$source_type" = "bundle" ]; then
    deployed_identifier="$(echo "$salt$new_bundle_hash" | shasum | cut -d' ' -f1)"
fi

bash "$lit_base_path/scripts/telemetry.sh" <<EOF &
{
    "action": "deploy",
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
