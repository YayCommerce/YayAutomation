#!/bin/bash
# Run a temporary workflow on temporary orders and product (wp-cli), print its emails, notes and meta as JSON.
# Mail is really sent (local Mailpit); workflow, orders, product, runs and jobs are removed, leftovers printed.
#   ./run.sh check-orders [--wp-env|--wp-env-min]
# Before / after a change: run once on each version (> before.json, > after.json), then diff.
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1

"${WP[@]}" eval-file dev/scripts/check-orders.php
