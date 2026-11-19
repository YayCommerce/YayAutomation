#!/bin/bash
# Check the login triggers: claim cooldown on the real table, then real sign-ins over HTTP (curl) against the site, counting the runs they start. Removes its rows.
#   ./run.sh check-login [--wp-env|--wp-env-min]
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
. dev/scripts/lib/wp-target.sh "$1" check || exit 1
[[ -n $1 ]] || { echo "Signs people in over HTTP: run it on --wp-env or --wp-env-min."; exit 1; }

phase() { "${WP[@]}" eval-file dev/scripts/check-login.php "$@" 2>&1; }
fails=0
pass() { echo "PASS $1"; }
fail() { echo "FAIL $1"; fails=$((fails + 1)); }
# PASS / FAIL lines a phase printed count too.
report() { grep -E '^(PASS|FAIL) ' <<<"$1"; fails=$((fails + $(grep -c '^FAIL ' <<<"$1"))); }

report "$(phase claims)"

home=$("${WP[@]}" option get home 2>/dev/null | tail -1 | tr -d '\r')
out=$(phase setup)
STATE=$(sed -n 's/^STATE //p' <<<"$out")
[[ -n $STATE ]] || { echo "$out"; echo "Setup failed."; exit 1; }
jars=$(mktemp -d -t check-login.XXXXXX)
extra_orders=""
cleanup() {
  phase cleanup "$STATE" "$extra_orders" >/dev/null
  rm -rf "$jars"
}
trap cleanup EXIT

json() { php -r '$s = json_decode( base64_decode( $argv[1] ), true ); $v = $s; foreach ( array_slice( $argv, 2 ) as $k ) { $v = $v[ $k ]; } echo is_array( $v ) ? json_encode( $v ) : $v;' "$STATE" "$@"; }
password=$(json password)
uid() { json users "$1" id; }
login_name() { json users "$1" login; }
# Runs of workflow $1 for user id $2.
runs() {
  local all
  all=$(phase runs "$STATE" | sed -n 's/^RUNS //p')
  php -r '$r = json_decode( $argv[1], true ); echo count( array_keys( $r[ $argv[2] ] ?? [], "user:" . $argv[3], true ) );' "$all" "$1" "$2"
}
expect() { # workflow, user id, count, what
  local got
  got=$(runs "$1" "$2")
  [[ $got == "$3" ]] && pass "$4" || fail "$4 (workflow $1: $got runs, expected $3)"
}

sessions() { "${WP[@]}" user session list "$1" --format=count 2>/dev/null | tail -1 | tr -d '\r'; }
password_is() { [[ $("${WP[@]}" eval "echo (int) wp_check_password( '$2', get_userdata( $1 )->user_pass, $1 );" 2>/dev/null | tail -1 | tr -d '\r') == 1 ]]; }
visit() { curl -s -o /dev/null -b "$jars/$1" -c "$jars/$1" "$home${2:-/}"; }
# wp-login.php, as a browser does: the test cookie first.
wp_login() {
  curl -s -o /dev/null -c "$jars/$1" "$home/wp-login.php"
  curl -s -o /dev/null -b "$jars/$1" -c "$jars/$1" --data-urlencode "log=$(login_name "$2")" --data-urlencode "pwd=$password" -d 'wp-submit=Log+In&testcookie=1' --data-urlencode "redirect_to=$home/" "$home/wp-login.php"
}
field() { sed -nE "s/.*name=\"$1\" value=\"([^\"]*)\".*/\1/p" | head -1; }
myaccount=$(json myaccount)
# The WooCommerce login form; $3 = the page it is posted to (My Account, or checkout's form).
wc_login() {
  local nonce
  nonce=$(curl -s -b "$jars/$1" -c "$jars/$1" "$myaccount" | field woocommerce-login-nonce)
  curl -s -o /dev/null -b "$jars/$1" -c "$jars/$1" --data-urlencode "username=$(login_name "$2")" --data-urlencode "password=$password" --data-urlencode "woocommerce-login-nonce=$nonce" -d 'login=Log+in' "${3:-$myaccount}"
}

# Sign-in on wp-login.php: confirmed on the next request, not in the one that made the session.
wp_login paid paid
expect customer "$(uid paid)" 0 'S1: no run in the request that signed in'
visit paid /
expect customer "$(uid paid)" 1 'T2: wp-login.php sign-in, confirmed on the next request'
expect customer2 "$(uid paid)" 1 'T16: a second workflow with the same trigger runs too'
expect user "$(uid paid)" 1 'User logged in, role customer'
expect inactive "$(uid paid)" 0 'N23: an inactive workflow does not run'
visit paid /shop/
visit paid "${myaccount#"$home"}"
expect customer "$(uid paid)" 1 'N5: browsing on after the sign-in is no second event'

# Sign out on My Account, sign in again, and another device: inside the cooldown.
logout=$(curl -s -b "$jars/paid" -c "$jars/paid" "$myaccount" | grep -oE 'href="[^"]*customer-logout[^"]*"' | head -1 | sed -E 's/href="([^"]*)"/\1/; s/&amp;/\&/g')
[[ -n $logout ]] && curl -s -o /dev/null -b "$jars/paid" -c "$jars/paid" "$logout"
before=$(sessions "$(uid paid)")
wp_login paid paid
visit paid /
expect customer "$(uid paid)" 1 'N4: sign out and in again inside the cooldown'
[[ -n $logout && $(sessions "$(uid paid)") -gt $before ]] && pass 'N4: … the sign-out link was used and the new sign-in made a session' || fail "N4: … sign-out link '$logout', sessions $before → $(sessions "$(uid paid)")"
wp_login phone paid
visit phone /
expect customer "$(uid paid)" 1 'N3: another device inside the cooldown'

phase age "$STATE" paid >/dev/null
wp_login laptop paid
visit laptop /
expect customer "$(uid paid)" 2 'T15: after the cooldown it runs again'

wc_login paid2 paid2
visit paid2 /
expect customer "$(uid paid2)" 1 'T1: My Account sign-in form'
wc_login paid3 paid3 "$(json checkout)"
visit paid3 /
expect customer "$(uid paid3)" 1 'T3: the login form posted to the checkout page'

for who in noorder buyer editor manager subscriber unpaid; do
  wp_login "$who" "$who"
  visit "$who" /
done
for who in noorder buyer editor manager subscriber unpaid; do
  [[ $(sessions "$(uid "$who")") -ge 1 ]] || fail "$who: the sign-in made no session"
done
expect user "$(uid noorder)" 1 'T4: role customer without an order: User logged in runs'
expect customer "$(uid noorder)" 0 'T4: … Customer logged in does not'
expect customer "$(uid buyer)" 1 'T19: a custom role without a protected capability, with a paid order'
expect customer "$(uid editor)" 0 'N11: a custom role with a protected capability'
expect customer "$(uid manager)" 0 'N10: staff with a paid order'
expect user "$(uid subscriber)" 0 'N12: a role not in roles'
expect customer "$(uid unpaid)" 0 'N13: only failed, cancelled and fully refunded orders'

# A double-submitted sign-in: two sessions, two confirmations, one run.
wp_login twice1 paid4 & wp_login twice2 paid4 & wait
visit twice1 / & visit twice2 / & wait
expect customer "$(uid paid4)" 1 'N20: a double-submitted sign-in starts one run'

# Second factor: the password step's session is ended before any request uses it; the one after the code is confirmed.
wp_login paid5 paid5
phase destroy "$STATE" paid5 >/dev/null
visit paid5 /
expect customer "$(uid paid5)" 0 'N15: the session ended before the code is never confirmed'
wp_login paid5 paid5
visit paid5 /
expect customer "$(uid paid5)" 1 'T11 (mechanism): the session made after the code runs once'

report "$(phase nocookie "$STATE")"
visit anonymous /
expect customer "$(uid paid6)" 0 'N17: cookies never sent, no run'

# My Account sign-up with its automatic sign-in.
email="reg@$(json domain)"
nonce=$(curl -s -c "$jars/reg" "$myaccount" | field woocommerce-register-nonce)
curl -s -o /dev/null -b "$jars/reg" -c "$jars/reg" --data-urlencode "email=$email" --data-urlencode "password=$password" --data-urlencode "woocommerce-register-nonce=$nonce" -d 'register=Register' "$myaccount"
visit reg /
reg_id=$("${WP[@]}" user get "$email" --field=ID 2>/dev/null | tail -1 | tr -d '\r')
expect user_auto "$reg_id" 1 'T5: My Account sign-up, auto on: runs with how = auto'
expect user "$reg_id" 0 'N14: … auto off: no run'

# Checkout's account creation for a guest with an older paid order.
out=$(phase checkout "$STATE")
cookie=$(sed -n 's/^COOKIE //p' <<<"$out")
guest_id=$(sed -n 's/^USER //p' <<<"$out")
extra_orders=$(sed -n 's/^ORDER //p' <<<"$out")
curl -s -o /dev/null -b "$cookie" "$home/"
expect auto "$guest_id" 1 'T6: account made at checkout, older paid guest order, auto on'
expect customer "$guest_id" 0 'N14: … auto off: no run'

# Password change in My Account: the cookie is reissued for the same session.
phase age "$STATE" paid >/dev/null
page=$(curl -s -b "$jars/laptop" -c "$jars/laptop" "${myaccount%/}/edit-account/")
nonce=$(field save-account-details-nonce <<<"$page")
curl -s -o /dev/null -b "$jars/laptop" -c "$jars/laptop" --data-urlencode "account_first_name=Pat" --data-urlencode "account_last_name=Paid" --data-urlencode "account_display_name=Pat" --data-urlencode "account_email=$(json users paid email)" --data-urlencode "password_current=$password" --data-urlencode "password_1=${password}2" --data-urlencode "password_2=${password}2" --data-urlencode "save-account-details-nonce=$nonce" -d 'action=save_account_details&save_account_details=Save' "${myaccount%/}/edit-account/"
visit laptop /
visit laptop /shop/
expect customer "$(uid paid)" 2 'N1: password change, no run'
password_is "$(uid paid)" "${password}2" && pass 'N1: … the password did change' || fail 'N1: … the password did not change'

# Password reset in My Account while signed in: WooCommerce makes a new session for the same person.
phase age "$STATE" paid2 >/dev/null
before=$(sessions "$(uid paid2)")
key=$("${WP[@]}" eval "echo get_password_reset_key( get_userdata( $(uid paid2) ) );" 2>/dev/null | tail -1 | tr -d '\r')
curl -s -o /dev/null -L -b "$jars/paid2" -c "$jars/paid2" "${myaccount%/}/lost-password/?key=$key&id=$(uid paid2)"
page=$(curl -s -b "$jars/paid2" -c "$jars/paid2" "${myaccount%/}/lost-password/?show-reset-form=true")
nonce=$(field woocommerce-reset-password-nonce <<<"$page")
curl -s -o /dev/null -b "$jars/paid2" -c "$jars/paid2" --data-urlencode "reset_key=$key" --data-urlencode "reset_login=$(login_name paid2)" --data-urlencode "password_1=${password}3" --data-urlencode "password_2=${password}3" --data-urlencode "woocommerce-reset-password-nonce=$nonce" -d 'wc_reset_password=true' "${myaccount%/}/lost-password/"
visit paid2 /
visit paid2 /shop/
expect customer "$(uid paid2)" 1 'N2: password reset while signed in, no run'
password_is "$(uid paid2)" "${password}3" && [[ $(sessions "$(uid paid2)") -ge 1 ]] && pass "N2: … the password was reset and WooCommerce signed the person in again (sessions $before → $(sessions "$(uid paid2)"))" || fail "N2: … reset not done (sessions $before → $(sessions "$(uid paid2)"))"

# Without a license the workflow needs YayMatik Pro: nothing runs.
phase age "$STATE" paid3 >/dev/null
phase edition lite >/dev/null
wp_login lite paid3
visit lite /
phase edition pro >/dev/null
expect customer "$(uid paid3)" 1 'N22: Pro without a license: no run'
[[ -s $jars/lite ]] && grep -q wordpress_logged_in "$jars/lite" && pass 'N22: … the sign-in worked' || fail 'N22: … the sign-in failed'

echo
[[ $fails == 0 ]] && echo "All checks passed." || echo "$fails check(s) failed."
[[ $fails == 0 ]]
