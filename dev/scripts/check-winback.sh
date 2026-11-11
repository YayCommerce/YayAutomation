#!/bin/bash
# Check win-back (wp-cli): trigger claims, the inactive sweep (window, statuses, the last order, claims, batches), its job and health row, the template graphs end to end, preview. Removes its rows.
#   ./run.sh check-winback [--wp-env|--wp-env-min]
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1

"${WP[@]}" eval-file dev/scripts/check-winback.php
