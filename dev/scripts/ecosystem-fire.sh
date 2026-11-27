#!/bin/bash
# Fire a scaffold's trigger (dev/ecosystem) with a sample payload, run the runs it starts, and list them.
#   ./run.sh ecosystem-fire <trigger> [--wp-env]
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
[[ -n $1 ]] || { echo "Usage: ./run.sh ecosystem-fire <trigger> [--wp-env]"; exit 1; }
trigger=$1
. dev/scripts/lib/wp-target.sh "$2" check || exit 1
# wp-env's wp-config has no YAYMATIK_IS_DEVELOPMENT: each wp-cli process defines it, so the scaffolds load there too.
[[ $2 == --wp-env ]] && WP+=(--exec="define( 'YAYMATIK_IS_DEVELOPMENT', true );")

after=$("${WP[@]}" eval-file dev/scripts/ecosystem-fire.php fire "$trigger") || exit 1
"${WP[@]}" action-scheduler run --hooks=yaymatik_run --batches=5 --quiet || exit 1
"${WP[@]}" eval-file dev/scripts/ecosystem-fire.php report "$trigger" "$after"
