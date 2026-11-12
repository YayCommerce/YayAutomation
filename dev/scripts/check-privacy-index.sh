#!/bin/bash
# Check the privacy index (wp-cli): every lookup against the old full scan, before and after an erase and a delete. Removes its runs.
#   ./run.sh check-privacy-index [--wp-env|--wp-env-min]
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1

"${WP[@]}" eval-file dev/scripts/check-privacy-index.php
