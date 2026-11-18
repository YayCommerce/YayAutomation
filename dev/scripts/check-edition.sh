#!/bin/bash
# Check a store moving from YayMatik Pro to the free edition and back (wp-cli, wp-env only): workflows using Pro neither start nor activate, a waiting run stops before a Pro step, a save keeps their Pro settings, and back on Pro all work.
#   ./run.sh check-edition --wp-env
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
# The free edition comes from dev/wp/mu-plugins/yaymatik-dev-license.php, which only wp-env loads.
[[ $1 == --wp-env ]] || { echo "Refused: check-edition runs on --wp-env only."; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1

fails=0
step() { "${WP[@]}" eval-file dev/scripts/check-edition.php "$@" || fails=$((fails + 1)); }
edition() { if [[ $1 == lite ]]; then "${WP[@]}" option update yaymatik_dev_edition lite >/dev/null; else "${WP[@]}" option delete yaymatik_dev_edition >/dev/null 2>&1; fi; }
trap 'edition pro; "${WP[@]}" eval-file dev/scripts/check-edition.php cleanup >/dev/null' EXIT

echo '--- YayMatik Pro: workflows using Pro, one run waiting'
edition pro
step pro-setup
echo '--- the free edition'
edition lite
step lite
echo '--- back to YayMatik Pro'
edition pro
step pro-again

trap - EXIT
step cleanup
echo "$([[ $fails == 0 ]] && echo 'All passed' || echo "$fails step(s) failed")"
exit $((fails > 0))
