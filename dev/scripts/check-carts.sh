#!/bin/bash
# Check cart tracking on the local site (wp-cli): writes, hash skip, empty, logout, login, orders. Removes its rows.
#   ./run.sh check-carts
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
command -v wp >/dev/null || { echo "Missing: wp-cli"; exit 1; }

wp ${WP_PATH:+--path="$WP_PATH"} eval-file dev/scripts/check-carts.php
