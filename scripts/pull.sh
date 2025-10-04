#!/bin/bash

set -e

lit_base_path="$1"
project_base_path="$(pwd)"

if [ -z "$2" ]; then
    is_forcing=false
elif [ "$2" = "--force" ]; then
    is_forcing=true
else
    echo "usage: lit pull [--force]"

    exit 1
fi

source "$lit_base_path/scripts/helpers.sh"

if [ ! -d "$project_base_path/lit" ]; then
    echo "fatal: not a lit directory"

    exit 1
fi

git_repository_url="$(get_file_value "$project_base_path/lit/git-repository-url")"
current_branch="$(get_file_value "$project_base_path/lit/current-branch")"
current_commit="$(get_file_value "$project_base_path/lit/current-commit")"

for release_directory_path in "$project_base_path/releases/"*/ ; do
    if [[ -e "$release_directory_path" ]] && ! [[ $release_directory_path =~ /[0-9]+/$ ]] ; then
       echo -e "The name of existing release directory \"$release_directory_path\" is not fully numeric, this should never happen."

       exit 1
    fi
done

current_release_id=$(ls "$project_base_path/releases" | sort --numeric-sort | tail -n1) || 0;

new_release_id="$((current_release_id + 1))"

remote_branch_info=$(git ls-remote --symref "$git_repository_url" "$current_branch")

current_remote_commit=$(echo "$remote_branch_info" | grep -v "ref: refs/heads/" | cut -f1)

if [ "$current_commit" = "$current_remote_commit" ]; then
    echo "Latest commit of \"$current_branch\" is already deployed (${current_remote_commit:0:8})"
    
    if [ "$is_forcing" = true ]; then        
        echo "Using \"--force\", redeploying..."
    else
        echo "Run \"lit pull --force\" to redeploy"

        exit 0
    fi    
fi

if [ -f "$project_base_path/lit/reusing-enabled" ]; then
    reusing_enabled=true
else
    reusing_enabled=false
fi

if [ "$reusing_enabled" = true ]; then            
    if [ -L "$project_base_path/lit/hooks/before-storing-for-reuse.sh" ]; then
        before_storing_for_reuse_hook_file_path="$(readlink -f "$project_base_path/lit/hooks/before-storing-for-reuse.sh")"
    elif [ -f "$project_base_path/lit/hooks/before-storing-for-reuse.sh" ]; then
        before_storing_for_reuse_hook_file_path="$project_base_path/lit/hooks/before-storing-for-reuse.sh"
    else
        echo "Hook does not exist: \"$(basename "$project_base_path")/lit/hooks/before-storing-for-reuse.sh\""

        before_storing_for_reuse_hook_file_path="$project_base_path/lit/reusing-enabled"
    fi

    before_storing_for_reuse_hook_hash="$(sha1sum "$before_storing_for_reuse_hook_file_path" | cut -d' ' -f1)"

    tar_file_path=""

    if [ -f "$lit_base_path/releases/$current_remote_commit-$before_storing_for_reuse_hook_hash.tar.zst" ]; then
        tar_file_path="$lit_base_path/releases/$current_remote_commit-$before_storing_for_reuse_hook_hash.tar.zst"
    elif [ -f "$lit_base_path/releases/$current_remote_commit-$before_storing_for_reuse_hook_hash.tar.gz" ]; then
        tar_file_path="$lit_base_path/releases/$current_remote_commit-$before_storing_for_reuse_hook_hash.tar.gz"
    elif ls "$lit_base_path/releases/$current_remote_commit-"* >/dev/null 2>&1; then
        echo "Commit ${current_remote_commit:0:8} was deployed before, but it can't be reused because it was prepared by a different \"before-storing-for-reuse.sh\" hook"
    fi

    if [ -n "$tar_file_path" ]; then
        echo "Reusing deployment from cache"       
    else
        temp_directory_path="$lit_base_path/releases/wip_$(generate_uuid)"

        mkdir -p "$temp_directory_path"

        git clone --branch "$current_branch" \
            --depth 25 \
            --single-branch \
            "$git_repository_url" "$temp_directory_path"

        cd "$temp_directory_path"

        current_commit="$(git rev-parse HEAD)"

        echo "Running \"$(basename "$project_base_path")/lit/hooks/before-storing-for-reuse.sh\"..."

        bash "$before_storing_for_reuse_hook_file_path" "$temp_directory_path"

        staging_directory_path="$lit_base_path/releases/$current_commit-$before_storing_for_reuse_hook_hash"

        if [ -d "$staging_directory_path" ]; then
            rm -rf "$staging_directory_path"
            rm -f "$staging_directory_path.tar.zst"
            rm -f "$staging_directory_path.tar.gz"
        fi

        mv "$temp_directory_path" "$staging_directory_path"

        cd "$lit_base_path/releases"

        if command -v zstd >/dev/null 2>&1; then
            echo "Caching release for reuse, compressing with zstd... "

            tar --use-compress-program "zstd -T0 -3" -cf "$staging_directory_path.tar.zst" "$(basename "$staging_directory_path")"

            tar_file_path="$staging_directory_path.tar.zst"
        else
            echo "Caching release for reuse, compressing... "

            tar -czf "$staging_directory_path.tar.gz" "$(basename "$staging_directory_path")"

            tar_file_path="$staging_directory_path.tar.gz"
        fi

        rm -rf "$staging_directory_path"
    fi

    echo "Creating \"$(basename "$project_base_path")/releases/$new_release_id\" for the new release..."

    mkdir "$project_base_path/releases/$new_release_id"

    cd "$project_base_path/releases/$new_release_id"

    echo "Extracting release..."

    tar --strip-components=1 --extract --file "$tar_file_path"
else
    echo "Creating \"$(basename "$project_base_path")/releases/$new_release_id\" for the new release..."

    mkdir "$project_base_path/releases/$new_release_id"

    cd "$project_base_path/releases/$new_release_id"

    git clone --branch "$current_branch" \
        --depth 25 \
        --single-branch \
        "$git_repository_url" "$project_base_path/releases/$new_release_id"

    current_commit="$(git rev-parse HEAD)"
fi

