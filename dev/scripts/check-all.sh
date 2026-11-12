#!/bin/bash
# Run every PASS/FAIL check on one site, keep going on a failure, print one PASS / FAIL / SKIP table; exits 1 on any FAIL. Target: local site, --wp-env or --wp-env-min.
#   ./run.sh check-all [--wp-env|--wp-env-min]
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1

# check-orders and check-status-flow print JSON to diff before / after a change, so they are not here.
# plugin-lifecycle uninstalls the plugin and reseeds the site, so it runs last and on wp-env only.
checks=(storage runs late-module carts privacy-index lifecycle winback catalog settings plugin-lifecycle)
wp_env_only=(plugin-lifecycle)
logs=$(mktemp -d -t yaymatik-check-all.XXXXXX) || exit 1
results=()
failed=0
for name in "${checks[@]}"; do
  if [[ $1 != --wp-env* && " ${wp_env_only[*]} " == *" $name "* ]]; then
    results+=("SKIP check-$name (wp-env only)")
    continue
  fi
  echo "=== check-$name"
  if dev/scripts/check-"$name".sh "$1" > "$logs/$name.log" 2>&1; then
    results+=("PASS check-$name")
  else
    results+=("FAIL check-$name")
    failed=1
    tail -20 "$logs/$name.log"
  fi
done

echo
printf '%s\n' "${results[@]}"
echo "Logs: $logs"
exit $failed
