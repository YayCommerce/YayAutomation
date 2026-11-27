#!/bin/bash
# Check the requirements check, deactivate and uninstall (wp-cli, wp-env only): forced WordPress 6.8, jobs on deactivate and reactivate, both zips active at once, what uninstall keeps or deletes with YAYMATIK_REMOVE_ALL_DATA. Ends with reset-data and seed.
#   ./run.sh check-plugin-lifecycle --wp-env|--wp-env-min
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
source dev/scripts/lib/wporg.sh
# It uninstalls the plugin and drops its tables: only on a throwaway site.
[[ $1 == --wp-env || $1 == --wp-env-min ]] || { echo "Refused: check-plugin-lifecycle runs on --wp-env or --wp-env-min only."; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1
# The zips install as $WPORG_SLUG and yaymatik-pro, and are deleted afterwards: never the mounted checkout.
[[ $PLUGIN_FOLDER == "$WPORG_SLUG" || $PLUGIN_FOLDER == yaymatik-pro ]] && { echo "Refused: the checkout folder is $PLUGIN_FOLDER, the name a zip installs as. Clone it under another name."; exit 1; }

fails=0
step() { "${WP[@]}" "$@" || fails=$((fails + 1)); }
check() { step eval-file dev/scripts/check-plugin-lifecycle.php "$@"; }
# On wp-env the plugin folder is this git checkout: an uninstall without --skip-delete would delete it.
uninstall() { "${WP[@]}" plugin deactivate "$PLUGIN_FOLDER" && "${WP[@]}" plugin uninstall "$PLUGIN_FOLDER" --skip-delete; }
old_wp=(--require=dev/scripts/check-plugin-lifecycle.php)

restore() {
  "${WP[@]}" config delete YAYMATIK_REMOVE_ALL_DATA >/dev/null 2>&1
  "${WP[@]}" plugin delete "$WPORG_SLUG" yaymatik-pro >/dev/null 2>&1
  "${WP[@]}" plugin activate "$PLUGIN_FOLDER" >/dev/null
  check cleanup
  ./run.sh reset-data "$1" --yes >/dev/null && ./run.sh seed "$1" >/dev/null || fails=$((fails + 1))
}
trap 'restore "$1"' EXIT

echo '--- requirements: WordPress 6.8'
step "${old_wp[@]}" eval-file dev/scripts/check-plugin-lifecycle.php old-wp

echo '--- deactivate'
check schedule
step plugin deactivate "$PLUGIN_FOLDER"
check deactivated
step plugin activate "$PLUGIN_FOLDER"
check reactivated

echo '--- deactivate while the requirements fail'
check schedule
step "${old_wp[@]}" plugin deactivate "$PLUGIN_FOLDER"
check deactivated
step plugin activate "$PLUGIN_FOLDER"

echo '--- YayMatik and YayMatik Pro both active'
# The zips from ./run.sh release and ./run.sh release pro --rc. The free plugin steps aside for Pro whatever the activation order
# (its folder loads first): it deactivates itself on the next admin request.
lite_zip=release/$WPORG_SLUG.zip
if [[ -f $lite_zip && -f release/yaymatik-pro.zip ]]; then
  step plugin deactivate "$PLUGIN_FOLDER"
  step plugin install "$lite_zip" release/yaymatik-pro.zip --force
  for order in "$WPORG_SLUG yaymatik-pro" "yaymatik-pro $WPORG_SLUG"; do
    "${WP[@]}" plugin deactivate "$WPORG_SLUG" yaymatik-pro >/dev/null 2>&1
    out=$(for plugin in $order; do "${WP[@]}" plugin activate "$plugin" 2>&1; done
      # Only the YayMatik copies' admin_init closures run (WooCommerce's needs wp-admin).
      "${WP[@]}" eval-file dev/scripts/check-plugin-lifecycle.php second-copy 2>&1)
    if grep -qE 'Fatal error|PHP (Fatal|Warning|Notice)|^(Warning|Notice): ' <<< "$out"; then
      echo "FAIL both active ($order): PHP error or notice"; echo "$out"
      fails=$((fails + 1))
    fi
    if [[ $("${WP[@]}" plugin get yaymatik-pro --field=status) == active && $("${WP[@]}" plugin get "$WPORG_SLUG" --field=status) == inactive ]]; then
      echo "PASS both active ($order activated in this order): yaymatik-pro stays, $WPORG_SLUG is deactivated"
    else
      echo "FAIL both active ($order): expected yaymatik-pro active and $WPORG_SLUG inactive"
      fails=$((fails + 1))
    fi
  done
  step plugin delete "$WPORG_SLUG" yaymatik-pro
  step plugin activate "$PLUGIN_FOLDER"
else
  echo "SKIP both active: build $lite_zip and release/yaymatik-pro.zip first"
fi

echo '--- uninstall, YAYMATIK_REMOVE_ALL_DATA not set'
check prepare
uninstall || fails=$((fails + 1))
check uninstalled-kept
step plugin activate "$PLUGIN_FOLDER"

echo '--- uninstall, YAYMATIK_REMOVE_ALL_DATA true'
check prepare
step config set YAYMATIK_REMOVE_ALL_DATA true --raw
uninstall || fails=$((fails + 1))
check uninstalled-all
step config delete YAYMATIK_REMOVE_ALL_DATA

echo '--- restore'
trap - EXIT
restore "$1"
echo "$([[ $fails == 0 ]] && echo 'All passed' || echo "$fails step(s) failed")"
exit $((fails > 0))
