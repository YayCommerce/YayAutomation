#!/bin/bash
# Check cart tracking and abandoned carts (wp-cli): writes, hash skip, empty, logout, login, orders, capture, restore, cleanup, privacy export / erase, sweep, cart triggers, one series per email, stop on order, cart email blocks, preview, run links, Activate with tracking off. Removes its rows.
#   ./run.sh check-carts [--wp-env|--wp-env-min]
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1

"${WP[@]}" eval-file dev/scripts/check-carts.php
