#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$2"

if [ "$3" = "--use-commit-from-checkout" ]; then
    current_remote_commit="$4"
elif [ -n "$4" ] || ([ -n "$3" ] && [ "$3" != "--force" ]); then
    printf 'usage: lit deploy [--force]\n'

    echo "failed (invalid usage)" > "$project_base_path/current-run-result"

    exit 1
fi

is_forcing=$([ "$3" = "--force" ] && echo true || echo false)

source "$lit_base_path/scripts/helpers.sh"

source_type="$(get_source_type "$project_base_path")"

# This should never happen unless files were manually tampered with.
if [ "$source_type" != "git" ] && [ "$source_type" != "bundle" ]; then
    printf 'Invalid source type: "%s"\n' "$source_type"

    echo "failed (invalid source type)" > "$project_base_path/current-run-result"

    exit 1
fi

started_at=$(current_time_in_ms)

releases_directory="$project_base_path/releases"
current_directory_path="$project_base_path/current"

# Projects previously deployed with Deployer have a "shared" directory.
if [[ -d "$project_base_path/shared/storage" ]]; then
    real_storage_directory_path="$project_base_path/shared/storage"
else
    real_storage_directory_path="$project_base_path/storage"
fi

if [[ -f "$project_base_path/shared/.env" ]]; then
    real_env_file_path="$project_base_path/shared/.env"
else
    real_env_file_path="$project_base_path/.env"
fi

if [[ ! -s "$real_env_file_path" ]]; then
    touch "$real_env_file_path"

    printf 'Your ".env" file is empty, try again when you have filled it in\n'

    echo 'aborted, the ".env" file is empty' > "$project_base_path/current-run-result"

    exit 1
fi

release_directory_created=false
was_released=false

on_exit() {
    script_status_code=$?

    cd "$project_base_path"

    if [[ "$release_directory_created" == true && "$was_released" == false ]]; then
        printf 'Deleting new but unreleased release directory "%s"\n' "$new_release_directory"

        rm -rf "$new_release_directory"
    fi

    # Clean up temp directories from cache building if they still exist
    if [[ -n "$temp_directory_path" && -d "$temp_directory_path" ]]; then
        rm -rf "$temp_directory_path"
    fi

    if [[ -n "$staging_directory_path" && -d "$staging_directory_path" ]]; then
        rm -rf "$staging_directory_path"
    fi

    if [ ! -f "$project_base_path/current-run-result" ]; then
        if [[ "$was_released" == true ]] && [[ "$script_status_code" -ne 0 ]] && [ "$source_type" = "git" ]; then
            echo "had errors, still deployed branch \"$current_branch\" (commit: ${current_commit:0:11})" > "$project_base_path/current-run-result"
        elif [[ "$was_released" == true ]] && [[ "$script_status_code" -ne 0 ]] && [ "$source_type" = "bundle" ]; then
            echo "had errors, still deployed bundle (hash: $new_bundle_hash)" > "$project_base_path/current-run-result"
        elif [[ "$was_released" == true ]] && [ "$source_type" = "git" ]; then
            echo "deployed branch \"$current_branch\" (commit: ${current_commit:0:11})" > "$project_base_path/current-run-result"
        elif [[ "$was_released" == true ]] && [ "$source_type" = "bundle" ]; then
            echo "deployed bundle (hash: $new_bundle_hash)" > "$project_base_path/current-run-result"
        elif [[ "$release_directory_created" == true ]]; then
            echo "failed, deployment was not released" > "$project_base_path/current-run-result"
        elif [[ "$script_status_code" -ne 0 ]] && [[ "$was_released" == false ]]; then
            echo "failed" > "$project_base_path/current-run-result"
        fi
    fi

    if [[ "$was_released" == true ]] && [[ "$script_status_code" -ne 0 ]]; then
        printf '>\n'
        printf '> Warning: The new deployment was still released!\n'
        printf '>\n'
    fi

    if [[ "$script_status_code" -ne 0 ]]; then
        if [ -f "$project_base_path/hooks/on-failure.sh" ]; then
            if ! cat "$project_base_path/hooks/on-failure.sh" | bash -se -- "$project_base_path" "$was_released"; then
                printf 'The on-failure hook failed\n'
            fi
        else
            printf 'Wanted to run "%s/hooks/on-failure.sh" but it does not exist\n' "$project_base_path"
        fi
    fi

    runtime_in_ms=$(( $(current_time_in_ms) - started_at ))

    printf 'Finished %s (in %d.%02ds)\n' "$([ "$script_status_code" -ne 0 ] && echo "with errors" || echo "successfully")" "$((runtime_in_ms / 1000))" "$((runtime_in_ms % 1000 / 10))"

    exit "$script_status_code"
}
trap on_exit EXIT TERM

for release_directory_path in "$releases_directory/"*/ ; do
    if [[ -e "$release_directory_path" ]] && ! [[ $release_directory_path =~ /[0-9]+/$ ]] ; then
       printf 'The name of existing release directory "%s" is not fully numeric, this should never happen\n' "$release_directory_path"

       echo "failed, a release directory has an invalid name" > "$project_base_path/current-run-result"

       exit 1
    fi
done

# shellcheck disable=SC2012
# SC2012 = use find instead of ls to better handle non-alphanumeric filenames.
# Using `ls` here is safe because we just ensured all directory names are numeric.
current_release_id=$(ls "$releases_directory" | sort --numeric-sort | tail -n1)

new_release_directory="$releases_directory/$((current_release_id + 1))"

if [ "$source_type" = "git" ]; then
    git_repository_url="$(get_file_value "$project_base_path/git-repository-url")"
    current_branch="$(get_file_value "$project_base_path/git-branch")"
    current_commit="$(get_file_value "$project_base_path/git-commit")"

    # If we are deploying after a "lit checkout", then we already have the commit.
    if [[ -z "$current_remote_commit" ]]; then
        printf 'Reading branch "%s" of "%s"... ' "$current_branch" "$git_repository_url"

        current_remote_commit=$(git ls-remote --symref "$git_repository_url" "$current_branch" | grep -v "ref: refs/heads/" | cut -f1)

        printf '\n'
    fi

    if [ "$current_commit" = "$current_remote_commit" ]; then
        printf 'Latest commit of "%s" is already deployed (%s)\n' "$current_branch" "${current_remote_commit:0:11}"

        if [ "$is_forcing" = true ]; then
            printf 'Using "--force", redeploying...\n'
        else
            printf 'Run "lit deploy --force" to redeploy\n'

            echo "aborted, this commit is already deployed" > "$project_base_path/current-run-result"

            exit 0
        fi
    fi

    caching_enabled=$([ -f "$project_base_path/git-release-caching-enabled" ] && echo true || echo false)
    used_cache=false

    if [ "$caching_enabled" = true ]; then
        if [ -L "$project_base_path/hooks/before-caching.sh" ]; then
            before_caching_hook_path="$(readlink -f "$project_base_path/hooks/before-caching.sh")"
            before_caching_hook_hash="$(shasum "$before_caching_hook_path" | cut -d' ' -f1)"
        elif [ -f "$project_base_path/hooks/before-caching.sh" ]; then
            before_caching_hook_path="$project_base_path/hooks/before-caching.sh"
            before_caching_hook_hash="$(shasum "$before_caching_hook_path" | cut -d' ' -f1)"
        else
            before_caching_hook_path=""
            before_caching_hook_hash="no-hook"
        fi

        tar_file_path=""

        if [ -f "$lit_base_path/cached-releases/$current_remote_commit-$before_caching_hook_hash.tar" ]; then
            tar_file_path="$lit_base_path/cached-releases/$current_remote_commit-$before_caching_hook_hash.tar"
        elif ls "$lit_base_path/cached-releases/$current_remote_commit-"*.tar >/dev/null 2>&1; then
            printf 'Cached release found but hook changed, rebuilding...\n'
        fi

        if [ -n "$tar_file_path" ]; then
            printf 'Reusing deployment from cache'

            # Update timestamp so this cache entry isn't pruned
            touch "$tar_file_path"

            current_commit="$current_remote_commit"
            used_cache=true
        else
            temp_directory_path="$lit_base_path/cached-releases/wip_$(uuidgen | tr '[:upper:]' '[:lower:]')"

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

                hook_entry_directory=$(pwd)
                cat "$before_caching_hook_path" | bash -se -- "$temp_directory_path" "$project_base_path" "$lit_base_path"
                cd "$hook_entry_directory" || exit 1
            else
                printf 'Wanted to run "%s/hooks/before-caching.sh" but it does not exist\n' "$project_base_path"
            fi

            staging_directory_path="$lit_base_path/cached-releases/$current_commit-$before_caching_hook_hash"
            tar_file_path="$staging_directory_path.tar"

            rm -rf "$staging_directory_path"
            rm -f "$tar_file_path"

            mv "$temp_directory_path" "$staging_directory_path"

            cd "$lit_base_path/cached-releases"

            if command -v zstd >/dev/null 2>&1; then
                printf 'Caching release... '

                tar --use-compress-program "zstd -T0 -3" -cf "$tar_file_path" "$(basename "$staging_directory_path")"
            else
                printf 'Caching release... (tip: install "zstd" for faster caching)'

                tar -czf "$tar_file_path" "$(basename "$staging_directory_path")"
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
    bundle_url="$(get_file_value "$project_base_path/bundle-url")"
    current_bundle_hash="$(get_file_value "$project_base_path/bundle-hash")"

    caching_enabled=false
    used_cache=false

    # This file is only for git deployments, it should never exist unless the project was incorrectly
    # converted from git to a bundle. Delete this file to prevent any confusion in the status command or in telemetry.
    rm -f "$project_base_path/git-release-caching-enabled"

    bundle_hash_url="${bundle_url}.hash"
    remote_bundle_hash_from_hash_file=""

    mkdir -p "$lit_base_path/cached-releases"

    # To avoid downloading the full bundle, download just a file containing the bundle hash.
    printf 'Checking bundle version from "%s"... ' "$bundle_hash_url"

    set +e
    curl_result=$(curl --fail --silent --show-error --location --write-out $'\n__CURL_TIME__:%{time_total}' "$bundle_hash_url" 2>&1)
    curl_exit_code=$?
    set -e

    curl_output=$(echo "$curl_result" | grep -v '^__CURL_TIME__:')

    printf '(in %s seconds)\n' "$(echo "$curl_result" | grep '^__CURL_TIME__:' | cut -d: -f2 | awk '{printf "%.2f", $1}')"

    if [ $curl_exit_code -eq 0 ]; then
        remote_bundle_hash_from_hash_file=$(echo "$curl_output" | tr -d '[:space:]')

        if ! [[ "$remote_bundle_hash_from_hash_file" =~ ^[a-fA-F0-9]{40}$ ]]; then
            printf 'Warning: "%s" does not contain a valid SHA1 hash\n' "$bundle_hash_url"
            printf 'Hash file contents: %s\n' "$curl_output"
            remote_bundle_hash_from_hash_file=""
        elif [ "$is_forcing" = false ] && [ "$current_bundle_hash" = "$remote_bundle_hash_from_hash_file" ]; then
            printf 'Bundle is already deployed (hash: %s)\n' "$remote_bundle_hash_from_hash_file"
            printf 'Run "lit deploy --force" to redeploy\n'

            echo "aborted, same bundle is already deployed" > "$project_base_path/current-run-result"

            exit 0
        fi
    else
        printf 'Warning: %s\n' "$curl_output"
    fi

    temp_bundle_path="$project_base_path/bundle-for-current-deployment.tar"

    # Try to find bundle in cache by .hash file hash
    cached_bundle_path=""

    if [ -n "$remote_bundle_hash_from_hash_file" ] && [ -f "$lit_base_path/cached-releases/$remote_bundle_hash_from_hash_file.tar" ]; then
        cached_bundle_path="$lit_base_path/cached-releases/$remote_bundle_hash_from_hash_file.tar"
    fi

    if [ -n "$cached_bundle_path" ]; then
        printf 'Using cached bundle (hash: %s)\n' "$remote_bundle_hash_from_hash_file"

        cp "$cached_bundle_path" "$temp_bundle_path"

        touch "$cached_bundle_path"

        new_bundle_hash="$remote_bundle_hash_from_hash_file"
        used_cache=true
    else
        printf 'Downloading bundle from "%s"... ' "$bundle_url"

        rm -f "$temp_bundle_path"

        set +e
        curl_result=$(curl --fail --silent --show-error --location --write-out $'\n__CURL_TIME__:%{time_total}' "$bundle_url" -o "$temp_bundle_path" 2>&1)
        curl_exit_code=$?
        set -e

        if [ $curl_exit_code -ne 0 ]; then
            printf '\n'
            printf 'Failed to download bundle from "%s"\n' "$bundle_url"
            printf '%s\n' "$(echo "$curl_result" | grep -v '^__CURL_TIME__:')"

            echo "failed to download bundle" > "$project_base_path/current-run-result"

            rm -f "$temp_bundle_path"

            exit 1
        fi

        printf '(%s in %s seconds)\n' "$(ls -lah "$temp_bundle_path" | awk '{print $5}')" "$(echo "$curl_result" | grep '^__CURL_TIME__:' | cut -d: -f2 | awk '{printf "%.2f", $1}')"

        new_bundle_hash="$(shasum "$temp_bundle_path" | cut -d' ' -f1)"

        if [ -n "$remote_bundle_hash_from_hash_file" ] && [ "$remote_bundle_hash_from_hash_file" != "$new_bundle_hash" ]; then
            printf 'Warning: the hash from "%s" does not match the actual hash from "%s"\n' "$bundle_hash_url" "$bundle_url"
            printf 'Warning: actual bundle hash "%s", hash from hash file "%s"\n' "$new_bundle_hash" "$remote_bundle_hash_from_hash_file"
        fi

        if [ ! -f "$lit_base_path/cached-releases/$new_bundle_hash.tar" ]; then
            printf 'Adding bundle to cache (%s)\n' "$lit_base_path/cached-releases/$new_bundle_hash.tar"

            cp "$temp_bundle_path" "$lit_base_path/cached-releases/$new_bundle_hash.tar"
        else
            printf 'Bundle exists in cache, but using the downloaded bundle instead\n'

            touch "$lit_base_path/cached-releases/$new_bundle_hash.tar"
        fi
    fi

    if [ "$current_bundle_hash" = "$new_bundle_hash" ]; then
        printf 'Bundle is already deployed (hash: %s)\n' "$new_bundle_hash"

        if [ "$is_forcing" = true ]; then
            printf 'Using "--force", redeploying...\n'
        else
            rm -f "$temp_bundle_path"

            printf 'Run "lit deploy --force" to redeploy\n'

            echo "aborted, same bundle is already deployed" > "$project_base_path/current-run-result"

            exit 0
        fi
    fi

    printf 'Creating "%s" for the new release...\n' "$new_release_directory"

    mkdir "$new_release_directory"

    release_directory_created=true

    cd "$new_release_directory"

    mv "$temp_bundle_path" "$new_release_directory/lit-bundle.tar"

    printf 'Extracting bundle... '

    # We use "--strip-components=1" so we can use "--exclude-from={file}" when making the bundle, this
    # is the only reliable way to exclude files when making a tar. We don't want to make bundles using
    # "--exclude="node_modules" flags, because those apply to every file/directory with that name, which
    # for example makes it impossible to exclude node_modules in the root of your project, but include
    # the node_modules from your frontend/ directory.
    #
    # The "--warning" flag prevents warnings when the bundle was made on MacOS but extracted on Linux.
    tar --strip-components=1 --extract $(is_macos || echo "--warning=no-unknown-keyword") --file "$new_release_directory/lit-bundle.tar"

    rm -f "$new_release_directory/lit-bundle.tar"

    printf '\n'

    # Assuming "config/filesystems.php" is always present. if this file is in the root, then the bundle
    # wasn't made with "--strip-components" in mind.
    if [ -f "$new_release_directory/filesystems.php" ]; then
        printf '\n'
        printf 'Error: Incorrect bundle structure.\n'
        printf 'All entries in the bundle must be in a top-level directory.\n'
        printf '\n'
        printf 'Run "tar -tf {bundle}" to check. Entries should look like:\n'
        printf '  ./config/filesystems.php       (good)\n'
        printf '  my-app/config/filesystems.php  (good)\n'
        printf '  config/filesystems.php         (bad - missing top-level directory)\n'
        printf '\n'
        printf 'See: https://github.com/SjorsO/lit?tab=readme-ov-file#deploying-a-bundle\n'
        printf '\n'
        exit 1
    fi
fi

# Laravel needs this directory, make sure it exists even if it was excluded from the bundle.
mkdir -p "$new_release_directory/bootstrap/cache"

printf 'Creating a symlink to the storage directory\n'

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
    echo "$current_commit" > "$project_base_path/git-commit"
elif [ "$source_type" = "bundle" ]; then
    echo "$new_bundle_hash" > "$project_base_path/bundle-hash"
fi

if [ -f "$project_base_path/hooks/after-release.sh" ]; then
    hook_entry_directory=$(pwd)
    cat "$project_base_path/hooks/after-release.sh" | bash -se -- "$project_base_path" "$new_release_directory" "$lit_base_path"
    cd "$hook_entry_directory" || exit 1
else
    printf 'Wanted to run "%s/hooks/after-release.sh" but it does not exist\n' "$project_base_path"
fi

# Keep the 6 most recent releases. We need to keep several old releases because multiple quick deployments
# in a row could otherwise delete a release that a long-running job is still referencing.
#
# shellcheck disable=SC2012
# SC2012 = use find instead of ls to better handle non-alphanumeric filenames.
# We can safely use `ls` because we've already ensured all release directory names are numeric.
for old_release_directory in $(ls "$releases_directory" | sort --numeric-sort --reverse | tail -n+7) ; do
    printf 'Deleting old release directory "%s/%s"... ' "$releases_directory" "$old_release_directory"

    rm -rf "${releases_directory:?}/$old_release_directory"

    printf '\n'
done

# Prune cached releases older than 7 days, and limit total cache size to 500MB
if [ -n "$lit_base_path" ] && [ -d "$lit_base_path/cached-releases" ]; then
    find "$lit_base_path/cached-releases" -maxdepth 1 -type f -name "*.tar" -mtime +7 -delete 2>/dev/null

    max_cache_kb=$((500 * 1024))

    while true; do
        total_kb=$(du -sk "$lit_base_path/cached-releases" 2>/dev/null | cut -f1 || echo 0)

        if [ "$total_kb" -le "$max_cache_kb" ]; then
            break
        fi

        # Always keep at least 1 cache file
        if [ "$(ls "$lit_base_path/cached-releases"/*.tar 2>/dev/null | wc -l)" -le 1 ]; then
            break
        fi

        oldest_file=$(ls -t "$lit_base_path/cached-releases"/*.tar 2>/dev/null | tail -1)

        if [ -z "$oldest_file" ]; then
            break
        fi

        rm -f "$oldest_file"
    done
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
