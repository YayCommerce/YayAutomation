#!/bin/bash
# Check the review, welcome and high-value workflows on the local site (wp-cli): review data and block. Removes its rows.
#   ./run.sh check-lifecycle
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
command -v wp >/dev/null || { echo "Missing: wp-cli"; exit 1; }

wp ${WP_PATH:+--path="$WP_PATH"} eval-file dev/scripts/check-lifecycle.php
