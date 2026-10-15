#!/bin/bash
# End-to-end check of runs on the local site (wp-cli): real events → Dispatcher → Worker → Executor.
# Uses a temporary order and temporary workflows; mail is captured, not sent; everything is removed.
#   ./run.sh check-runs
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
command -v wp >/dev/null || { echo "Missing: wp-cli"; exit 1; }

wp ${WP_PATH:+--path="$WP_PATH"} eval-file dev/scripts/check-runs.php
