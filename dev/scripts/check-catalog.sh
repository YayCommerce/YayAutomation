#!/bin/bash
# Check the custom workflow catalog (wp-cli): active-trigger list, order meta guard; order parts on both order stores. Removes its rows.
#   ./run.sh check-catalog [--wp-env|--wp-env-min]
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1

"${WP[@]}" eval-file dev/scripts/check-catalog.php || exit 1
echo '--- posts store'
"${WP[@]}" --require=dev/scripts/check-catalog-posts.php eval-file dev/scripts/check-catalog.php
