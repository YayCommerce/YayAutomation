#!/bin/bash
# Check Settings and the Health check through REST (wp-cli): GET / PUT, each range, all-or-nothing save, one 400 per field, each check and fix. Restores the settings, removes its runs.
#   ./run.sh check-settings [--wp-env|--wp-env-min]
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1

"${WP[@]}" eval-file dev/scripts/check-settings.php
