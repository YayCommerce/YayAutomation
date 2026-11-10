#!/bin/bash
# Check the custom workflow catalog on the local site (wp-cli): active-trigger list, order meta guard; order parts on both order stores. Removes its rows.
#   ./run.sh check-catalog
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
command -v wp >/dev/null || { echo "Missing: wp-cli"; exit 1; }

wp ${WP_PATH:+--path="$WP_PATH"} eval-file dev/scripts/check-catalog.php || exit 1
echo '--- posts store'
wp ${WP_PATH:+--path="$WP_PATH"} --require=dev/scripts/check-catalog-posts.php eval-file dev/scripts/check-catalog.php
