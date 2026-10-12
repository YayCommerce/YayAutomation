#!/bin/bash
# Run a workflow on real orders (wp-cli), print its emails and notes as JSON. Mail not sent; notes removed.
#   ./run.sh check-orders [workflow id] [trigger id] [order ids…]   (default: 1695 order_created 1693 1694)
# Before / after a change: run once with the change stashed (> before.json), once with it, then diff.
cd "$(dirname "$0")/../.." || exit 1
[[ -f yay-automation.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
command -v wp >/dev/null || { echo "Missing: wp-cli"; exit 1; }

workflow=${1:-1695}
trigger=${2:-order_created}
shift $(( $# > 2 ? 2 : $# ))
orders=("$@")
[[ ${#orders[@]} -gt 0 ]] || orders=(1693 1694)

wp ${WP_PATH:+--path="$WP_PATH"} eval-file dev/scripts/check-orders.php "$workflow" "$trigger" "${orders[@]}"
