#!/bin/bash
# Add the fake store data (products, customer, cash on delivery, cart tracking). Safe to run again. Target: local site, --wp-env, --wp-env-min or --staging.
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" || exit 1

# As an argument, not eval-file: dev/ isn't on staging.
"${WP[@]}" eval "$(sed 1d dev/scripts/seed.php)"
