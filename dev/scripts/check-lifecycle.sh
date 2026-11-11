#!/bin/bash
# Check the review, welcome and high-value workflows (wp-cli): review data and block, account created (who starts it, billing fill, run subject, deleted users, privacy), site blocks, high-value alert. Removes its rows.
#   ./run.sh check-lifecycle [--wp-env|--wp-env-min]
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1

"${WP[@]}" eval-file dev/scripts/check-lifecycle.php
