#!/bin/bash
# Check the login triggers (wp-cli): claim cooldown, including two claims at once. Removes its rows.
#   ./run.sh check-login [--wp-env|--wp-env-min]
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1

"${WP[@]}" eval-file dev/scripts/check-login.php
