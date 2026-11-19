#!/bin/bash
# Data continuity on a throwaway wp-env site: install the first zip, seed it, replace the plugin with the second zip, check nothing was lost.
#   ./run.sh check-upgrade --wp-env <from.zip> <to.zip>
# A free `to` zip replaces the plugin in place (wp plugin install --force). A Pro zip (folder yaymatik-pro) is installed and activated
# with wp-env's dev license, then the free plugin is deactivated and deleted, as the Pro handoff says. Not part of check-all.
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
[[ $1 == --wp-env && -f $2 && -f $3 ]] || { echo "Usage: ./run.sh check-upgrade --wp-env <from.zip> <to.zip>"; exit 1; }
repo=$(pwd)
from=$2
to=$3
edition=lite
unzip -l "$to" | grep -q ' yaymatik-pro/yaymatik-pro.php$' && edition=pro
port=8930

# The site's own folder: its .wp-env.json, and what the container reads and writes (zips, this script, snapshots).
env=$(mktemp -d -t yaymatik-upgrade.XXXXXX) || exit 1
mkdir -p "$env/work"
cp "$from" "$env/work/from.zip"
cp "$to" "$env/work/to.zip"
cp dev/scripts/check-upgrade.php tests/Unit/Pro/fixtures/login-no-cart.json "$env/work/"
cat > "$env/.wp-env.json" <<JSON
{
  "core": null,
  "phpVersion": "8.1",
  "port": $port,
  "testsEnvironment": false,
  "plugins": ["https://downloads.wordpress.org/plugin/woocommerce.11.3.0.zip"],
  "mappings": {
    "wp-content/mu-plugins": "$repo/dev/wp/mu-plugins",
    "wp-content/yaymatik-upgrade": "$env/work"
  },
  "config": { "WP_DEBUG_LOG": true, "WP_DEBUG_DISPLAY": false }
}
JSON
wpenv() { (cd "$env" && "$repo/node_modules/.bin/wp-env" "$@"); }
wp() { wpenv run cli wp "$@" 2>&1; }
phase() { wp eval-file wp-content/yaymatik-upgrade/check-upgrade.php "$@"; }
cleanup() {
  wpenv destroy --force >/dev/null 2>&1
  rm -rf "$env"
}
trap cleanup EXIT

fails=0
report() { grep -E '^(PASS|FAIL) ' <<<"$1"; fails=$((fails + $(grep -c '^FAIL ' <<<"$1"))); }
step() { "$@" >/dev/null 2>&1 || { echo "FAIL $*"; fails=$((fails + 1)); }; }

echo "=== $(basename "$from") → $(basename "$to") ($edition), sha256 of the first: $(shasum -a 256 "$from" | cut -d' ' -f1)"
wpenv start >/dev/null 2>&1 || { echo "Could not start the throwaway site."; exit 1; }
step wpenv run cli wp plugin install wp-content/yaymatik-upgrade/from.zip --activate
report "$(phase seed)"
report "$(phase events order)"
report "$(phase events review)"
report "$(phase events rest)"
# The same requests on the old build first: debug.log then holds what this site logs without the new build.
requests() {
  local jar code
  jar=$(mktemp)
  curl -s -o /dev/null "http://localhost:$port/"
  curl -s -o /dev/null -c "$jar" "http://localhost:$port/wp-login.php"
  curl -s -o /dev/null -b "$jar" -c "$jar" -d 'log=admin&pwd=password&wp-submit=Log+In&testcookie=1' "http://localhost:$port/wp-login.php"
  code=$(curl -s -o /dev/null -w '%{http_code}' -b "$jar" "http://localhost:$port/wp-admin/admin.php?page=yaymatik")
  rm -f "$jar"
  echo "$code"
}
logged() { wpenv run cli cat wp-content/debug.log 2>/dev/null | grep -E 'PHP (Notice|Warning|Fatal|Deprecated|Parse)' | sed -E 's/^\[[^]]*\] //' | sort -u; }
requests >/dev/null
baseline=$(logged)
phase snapshot wp-content/yaymatik-upgrade/before.json >/dev/null

if [[ $edition == lite ]]; then
  step wpenv run cli wp plugin install wp-content/yaymatik-upgrade/to.zip --force
else
  step wpenv run cli wp plugin install wp-content/yaymatik-upgrade/to.zip --activate
  step wpenv run cli wp plugin deactivate yaymatik
  step wpenv run cli wp plugin delete yaymatik
fi
active=$(wp plugin list --status=active --field=name | grep -E '^yaymatik(-pro)?$' | tr -d '\r' | paste -sd, -)
[[ $active == "$([[ $edition == pro ]] && echo yaymatik-pro || echo yaymatik)" ]] && echo "PASS Active after the replace: $active" || { echo "FAIL Active after the replace: '$active'"; fails=$((fails + 1)); }

# One admin and one storefront request on the new build.
code=$(requests)
[[ $code == 200 ]] && echo "PASS The YayMatik admin page loads ($code)" || { echo "FAIL The YayMatik admin page: $code"; fails=$((fails + 1)); }

out=$(phase verify wp-content/yaymatik-upgrade/before.json "$edition")
report "$out"
echo "Changed keys: $(sed -n 's/^CHANGED //p' <<<"$out")"
report "$(phase finish)"
before=$(phase starts | sed -n 's/^BEFORE //p' | tr -d '\r')
report "$(phase started "$before")"
[[ $edition == pro ]] && report "$(phase pro wp-content/yaymatik-upgrade/login-no-cart.json)"

new=$(comm -13 <(echo "$baseline") <(logged))
[[ -z $baseline ]] || echo "Logged before the replace too (not counted): $(echo "$baseline" | cut -c1-160 | paste -sd'|' -)"
[[ -z $new ]] && echo "PASS No new PHP notice or warning in debug.log" || { echo "FAIL debug.log, new since the replace:"; echo "$new" | head -20; fails=$((fails + 1)); }

echo
[[ $fails == 0 ]] && echo "All checks passed." || echo "$fails check(s) failed."
[[ $fails == 0 ]]
