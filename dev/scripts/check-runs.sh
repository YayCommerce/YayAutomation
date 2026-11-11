#!/bin/bash
# End-to-end check of runs (wp-cli): real events → Dispatcher → Worker → Executor.
# Uses a temporary order and temporary workflows; mail is captured, not sent; everything is removed.
#   ./run.sh check-runs [--wp-env|--wp-env-min]
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1

"${WP[@]}" eval-file dev/scripts/check-runs.php
