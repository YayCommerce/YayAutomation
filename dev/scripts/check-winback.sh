#!/bin/bash
# Check win-back on the local site (wp-cli): trigger claims, the inactive sweep (window, statuses, the last order, claims), its job and health row. Removes its rows.
#   ./run.sh check-winback
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
command -v wp >/dev/null || { echo "Missing: wp-cli"; exit 1; }

wp ${WP_PATH:+--path="$WP_PATH"} eval-file dev/scripts/check-winback.php
