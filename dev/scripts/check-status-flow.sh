#!/bin/bash
# Run a temporary status-changed workflow (status, meta, note) on real orders (wp-cli); restores the orders.
#   ./run.sh check-status-flow [order ids…]   (default: 1693 1694)
# Before / after a change: run once with the change stashed (> before.json), once with it, then diff.
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
command -v wp >/dev/null || { echo "Missing: wp-cli"; exit 1; }

orders=("$@")
[[ ${#orders[@]} -gt 0 ]] || orders=(1693 1694)

wp ${WP_PATH:+--path="$WP_PATH"} eval-file dev/scripts/check-status-flow.php "${orders[@]}"
