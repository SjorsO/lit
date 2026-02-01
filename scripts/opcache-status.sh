set -e

lit_base_path="$1"
project_base_path="$2"

php_source_file="$lit_base_path/scripts/opcache-status.php"
action_label="get OPcache status"

if [ "${3:-}" = "--json" ]; then
    query_string="json"
fi

source "$lit_base_path/scripts/run-php-script.sh"
