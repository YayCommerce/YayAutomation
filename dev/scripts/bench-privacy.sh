#!/bin/bash
# Benchmark privacy lookups, old full scan vs the index, at 50k and 200k seeded runs (EXPLAIN, ms, write cost). wp-env only; removes its rows.
#   ./run.sh bench-privacy --wp-env|--wp-env-min [sizes…]
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
[[ $1 == --wp-env || $1 == --wp-env-min ]] || { echo "Refused: bench-privacy seeds 200k runs, so it runs on --wp-env or --wp-env-min only."; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1
shift
sizes=("$@")
[[ ${#sizes[@]} -eq 0 ]] && sizes=(50000 200000)

"${WP[@]}" eval-file dev/scripts/bench-privacy.php "${sizes[@]}"
