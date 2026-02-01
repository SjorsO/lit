set -e

lit_base_path="$1"
project_base_path="$2"

php_source_file="$lit_base_path/scripts/flush-opcache.php"
action_label="flush OPcache"
not_if_first_release="true"

source "$lit_base_path/scripts/run-php-script.sh"
