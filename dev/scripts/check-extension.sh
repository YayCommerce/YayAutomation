#!/bin/bash
# Check the example extension (dev/example-extension, installed as acme-rewards): register, a run sent from a background job, plugin off / on, privacy, measures (wp-cli).
#   ./run.sh check-extension [--wp-env|--wp-env-min]
# wp-env maps dev/example-extension to wp-content/plugins/acme-rewards (.wp-env.json; run `pnpm wp-env start` after pulling).
# On the local site, wp-content/plugins/acme-rewards must be that folder (a symlink to it).
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1

"${WP[@]}" plugin activate acme-rewards >/dev/null || { echo "FAIL acme-rewards (dev/example-extension) isn't installed on this site"; exit 1; }
"${WP[@]}" eval 'exit( false !== strpos( (string) file_get_contents( WP_PLUGIN_DIR . "/acme-rewards/acme-rewards.php" ), "function redeem(" ) ? 0 : 1 );' \
  || { echo "FAIL wp-content/plugins/acme-rewards isn't dev/example-extension"; exit 1; }

# Files inside the checkout, so the wp-env container sees them (wp-cli runs in the plugin folder on both
# targets): the mail log, and the hook that writes it in the background job's process (no --user: no one
# is logged in, as in WP-Cron).
mails=$(mktemp dev/.check-extension-mail.XXXXXX) && chmod 666 "$mails" || exit 1
hook=$(mktemp dev/.check-extension-hook.XXXXXX) && chmod 644 "$hook" || exit 1
trap 'rm -f "$mails" "$hook"' EXIT
cat > "$hook" <<PHP
<?php
WP_CLI::add_wp_hook(
    'wp_mail',
    function ( \$atts ) {
        \$headers = is_array( \$atts['headers'] ) ? \$atts['headers'] : explode( "\n", str_replace( "\r\n", "\n", (string) \$atts['headers'] ) );
        file_put_contents( 'dev/$(basename "$mails")', wp_json_encode( [ 'to' => implode( ', ', (array) \$atts['to'] ), 'subject' => \$atts['subject'], 'message' => \$atts['message'], 'headers' => array_values( array_filter( array_map( 'trim', \$headers ) ) ), 'user' => get_current_user_id(), 'post' => isset( \$GLOBALS['post'] ) ] ) . "\n", FILE_APPEND );
        return \$atts;
    }
);
PHP

results=()
step() {
  local label=$1
  shift
  if "$@"; then results+=("PASS $label"); else results+=("FAIL $label"); fi
}
phase() { "${WP[@]}" eval-file dev/scripts/check-extension.php "$@"; }

step "1 register + set up + fire" phase setup
step "2 background job sends" "${WP[@]}" --require="$hook" action-scheduler run --hooks=yaymatik_run --batches=5 --quiet
step "2 run, email, loop guard" phase sent "dev/$(basename "$mails")"
"${WP[@]}" plugin deactivate acme-rewards >/dev/null
step "3 plugin off" phase off
"${WP[@]}" plugin activate acme-rewards >/dev/null
step "4 plugin on again" phase on
step "5 privacy" phase privacy
phase measures
step "cleanup" phase cleanup

echo
printf '%s\n' "${results[@]}"
[[ " ${results[*]} " != *" FAIL "* ]]
