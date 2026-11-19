#!/bin/bash
# Check the login triggers: claim cooldown on the real table, then real sign-ins, returns, beacons, cart changes and the login template over HTTP (curl) against the site, counting the runs they start. Removes its rows.
#   ./run.sh check-login [--wp-env|--wp-env-min] [plugins]
# With `plugins`: only the sign-in plugins from wordpress.org (Two Factor, User Switching, OpenID Connect Generic against a
# local Keycloak in Docker), installed for the check and removed after it.
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
installed=""
cleanup() {
  [[ -n $installed ]] && "${WP[@]}" plugin uninstall --deactivate $installed >/dev/null 2>&1
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
# A form field's value, other attributes allowed between name and value.
field() { sed -nE "s/.*name=\"$1\"[^>]*value=\"([^\"]*)\".*/\1/p" | head -1; }
myaccount=$(json myaccount)
# The WooCommerce login form; $3 = the page it is posted to (My Account, or checkout's form).
wc_login() {
  local nonce
  nonce=$(curl -s -b "$jars/$1" -c "$jars/$1" "$myaccount" | field woocommerce-login-nonce)
  curl -s -o /dev/null -b "$jars/$1" -c "$jars/$1" --data-urlencode "username=$(login_name "$2")" --data-urlencode "password=$password" --data-urlencode "woocommerce-login-nonce=$nonce" -d 'login=Log+in' "${3:-$myaccount}"
}

plugins_checks() {
  # Versions are pinned, so the matrix in docs/workflow-core-design.md says what ran.
  installed="two-factor user-switching daggerhart-openid-connect-generic"
  for plugin in two-factor:0.17.0 user-switching:1.12.2 daggerhart-openid-connect-generic:3.11.4; do
    "${WP[@]}" plugin install "${plugin%%:*}" --version="${plugin##*:}" --activate >/dev/null 2>&1 || fail "Could not install $plugin"
  done

  # Two Factor (TOTP): the password step's session is ended at once; the session after the code is confirmed.
  key=$(phase twofactor "$STATE" tfa | sed -n 's/^KEY //p' | tr -d '\r')
  curl -s -o /dev/null -c "$jars/tfa" "$home/wp-login.php"
  page=$(curl -s -b "$jars/tfa" -c "$jars/tfa" --data-urlencode "log=$(login_name tfa)" --data-urlencode "pwd=$password" -d 'wp-submit=Log+In&testcookie=1' --data-urlencode "redirect_to=$home/" "$home/wp-login.php")
  visit tfa /
  visit tfa /shop/
  [[ $page == *validate_2fa_form* ]] && pass 'Two Factor: the password step asks for the code' || fail 'Two Factor: no code form'
  expect customer "$(uid tfa)" 0 'N15: Two Factor, before the code: no run'
  code=$(phase totp "$key" | sed -n 's/^CODE //p' | tr -d '\r')
  curl -s -o /dev/null -b "$jars/tfa" -c "$jars/tfa" --data-urlencode "provider=$(field provider <<<"$page")" --data-urlencode "wp-auth-id=$(field wp-auth-id <<<"$page")" --data-urlencode "wp-auth-nonce=$(field wp-auth-nonce <<<"$page")" --data-urlencode "authcode=$code" --data-urlencode "redirect_to=$home/" -o /dev/null "$home/wp-login.php?action=validate_2fa"
  [[ $(sessions "$(uid tfa)") -ge 1 ]] && pass 'Two Factor: the code signs the person in' || fail 'Two Factor: no session after the code'
  visit tfa /
  visit tfa /shop/
  expect customer "$(uid tfa)" 1 'T11: Two Factor, after the code: exactly one run'

  # User Switching: switching to a customer, back, and off is nobody signing in.
  admin_id=$("${WP[@]}" user get admin --field=ID 2>/dev/null | tail -1 | tr -d '\r')
  curl -s -o /dev/null -c "$jars/admin" "$home/wp-login.php"
  curl -s -o /dev/null -b "$jars/admin" -c "$jars/admin" -d 'log=admin&pwd=password&wp-submit=Log+In&testcookie=1' "$home/wp-login.php"
  visit admin /wp-admin/
  phase unclaim "$admin_id" >/dev/null
  admin_runs=$(runs anyone "$admin_id")
  link() { grep -oE "href=[\"'][^\"']*action=$1[^\"']*" | head -1 | sed -E "s/^href=[\"']//; s/&amp;/\\&/g; s/&#038;/\\&/g"; }
  to=$(curl -s -b "$jars/admin" -c "$jars/admin" "$home/wp-admin/user-edit.php?user_id=$(uid switch)" | link switch_to_user)
  curl -s -o /dev/null -L -b "$jars/admin" -c "$jars/admin" "$to"
  visit admin /
  visit admin /shop/
  [[ -n $to && $(sessions "$(uid switch)") -ge 1 ]] && pass 'User Switching: switched to the customer' || fail "User Switching: switch to failed ('$to')"
  expect anyone "$(uid switch)" 0 'N16: switch to a customer: no run'
  back=$(curl -s -b "$jars/admin" -c "$jars/admin" "$home/" | link switch_to_olduser)
  curl -s -o /dev/null -L -b "$jars/admin" -c "$jars/admin" "$back"
  visit admin /wp-admin/
  visit admin /
  [[ -n $back ]] && pass 'User Switching: switched back' || fail 'User Switching: no switch back link'
  expect anyone "$admin_id" "$admin_runs" 'N16: switch back: no run'
  off=$(curl -s -b "$jars/admin" -c "$jars/admin" "$home/wp-admin/" | link switch_off)
  curl -s -o /dev/null -L -b "$jars/admin" -c "$jars/admin" "$off"
  visit admin /
  [[ -n $off ]] && pass 'User Switching: switched off' || fail 'User Switching: no switch off link'
  expect anyone "$admin_id" "$admin_runs" 'N16: switch off: no run'

  # OpenID Connect Generic against Keycloak: a first sign-in makes the account (auto), a later one is a sign-in.
  if ! curl -s -o /dev/null http://localhost:8180/realms/yay; then
    echo "SKIP OpenID Connect: no Keycloak on localhost:8180 (realm yay)"
    return
  fi
  phase oidc >/dev/null
  idp=(--resolve host.docker.internal:8180:127.0.0.1)
  sso_login() {
    local start form
    start=$(curl -s -b "$jars/$1" -c "$jars/$1" "$home/wp-login.php" | grep -oE 'href="[^"]*openid-connect/auth[^"]*"' | head -1 | sed -E 's/href="([^"]*)"/\1/; s/&amp;/\&/g; s/&#038;/\&/g')
    form=$(curl -s "${idp[@]}" -L -b "$jars/$1" -c "$jars/$1" "$start" | grep -oE 'action="[^"]*login-actions/authenticate[^"]*"' | head -1 | sed -E 's/action="([^"]*)"/\1/; s/&amp;/\&/g')
    curl -s -o /dev/null "${idp[@]}" -L -b "$jars/$1" -c "$jars/$1" -d 'username=kc-buyer' -d 'password=Kc-buyer-1!' -d 'credentialId=' "$form"
    [[ -n $start && -n $form ]]
  }
  sso_login sso1 && pass 'OpenID Connect: signed in through Keycloak' || fail 'OpenID Connect: no login button or Keycloak form'
  visit sso1 /
  sso_id=$("${WP[@]}" user get kc-buyer --field=ID 2>/dev/null | tail -1 | tr -d '\r')
  [[ $sso_id =~ ^[0-9]+$ ]] && pass "OpenID Connect: the account was made (user $sso_id)" || fail 'OpenID Connect: no account made'

  expect anyone_auto "$sso_id" 1 'T9: OpenID Connect, first sign-in makes the account: one run, how = auto'
  expect anyone "$sso_id" 0 '… and none as a sign-in'
  phase unclaim "$sso_id" >/dev/null
  sso_login sso2 >/dev/null
  visit sso2 /
  visit sso2 /shop/
  expect anyone "$sso_id" 1 'T9: OpenID Connect, a later sign-in: exactly one run, how = sign_in'
}

if [[ $2 == plugins ]]; then
  plugins_checks
  echo
  [[ $fails == 0 ]] && echo "All checks passed." || echo "$fails check(s) failed."
  [[ $fails == 0 ]]
  exit
fi

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

# Return: a storefront visit after 12 hours with a saved session (Remember Me).
seen() { phase seen "$STATE" "$@" >/dev/null; }
seen_ago() { phase lastseen "$STATE" "$1" | sed -n 's/^SEEN //p' | tr -d '\r'; }
wp_login ret ret
visit ret /
seen ret none
visit ret /shop/
expect returning "$(uid ret)" 0 'N8: first visit without last seen: no event'
[[ $(seen_ago ret) =~ ^[0-9]+$ && $(seen_ago ret) -lt 60 ]] && pass 'N8: … last seen is written' || fail "N8: … last seen $(seen_ago ret)"
seen ret $((11 * 3600))
visit ret /
expect returning "$(uid ret)" 0 'N6: back after 11 hours: no event'
for _ in $(seq 1 24); do
  seen ret 3700
  visit ret /
done
expect returning "$(uid ret)" 0 'N7: a visit every hour for a day: no event'

# Requests that are not a person opening a page never count, with last seen 13 hours back.
seen ret $((13 * 3600))
before=$(seen_ago ret)
app=$("${WP[@]}" user application-password create "$(uid ret)" check-login --porcelain 2>/dev/null | tail -1 | tr -d '\r')
curl -s -o /dev/null -u "$(login_name ret):$app" "$home/wp-json/wp/v2/users/me"
curl -s -o /dev/null -b "$jars/ret" -d 'action=heartbeat&data[x]=1' "$home/wp-admin/admin-ajax.php"
curl -s -o /dev/null -b "$jars/ret" "$home/wp-json/wc/store/v1/cart"
curl -s -o /dev/null -b "$jars/ret" "$home/wp-cron.php"
curl -s -o /dev/null -I -b "$jars/ret" "$home/"
curl -s -o /dev/null -b "$jars/ret" -H 'Sec-Purpose: prefetch' "$home/"
curl -s -o /dev/null -b "$jars/ret" -H 'Purpose: prefetch' "$home/shop/"
curl -s -o /dev/null -b "$jars/ret" "$home/feed/"
expect returning "$(uid ret)" 0 'N18: REST (application password), AJAX, heartbeat, Store API, cron, HEAD, prefetch, feed: no event'
after=$(seen_ago ret)
[[ $after -ge $before ]] && pass "N18: … last seen untouched (${before}s → ${after}s back)" || fail "N18: … last seen moved (${before}s → ${after}s back)"

wp_login admin manager
visit admin /wp-admin/
seen manager $((13 * 3600))
visit admin /wp-admin/
visit admin /wp-admin/edit.php
expect staff "$(uid manager)" 0 'N19: staff using only wp-admin: no return'
visit admin /
expect staff "$(uid manager)" 1 'N19: … the same person on a store page returns'

visit ret /
expect returning "$(uid ret)" 1 'T12: back after 12 hours with a saved session: how = returned'
visit ret /shop/
expect returning "$(uid ret)" 1 '… and browsing on is no second event'

wp_login phone2 ret2
wp_login laptop2 ret2
visit phone2 /
visit laptop2 /
seen ret2 $((13 * 3600))
visit phone2 /
expect returning "$(uid ret2)" 1 'N9: phone after 13 hours: one return'
# The phone's claim goes, so only last seen can stop the laptop.
seen ret2 0
visit laptop2 /
expect returning "$(uid ret2)" 1 'N9: … then the laptop within 12 hours: none'

# The beacon: same-site only; two at once make one event.
beacon() { curl -s -o /dev/null -w '%{http_code}' -b "$jars/ret" -X POST "$@" "$home/wp-admin/admin-ajax.php?action=yaymatik_seen"; }
seen ret $((13 * 3600))
code=$(beacon -H 'Origin: https://evil.test')
code2=$(beacon -H 'Sec-Fetch-Site: cross-site')
expect returning "$(uid ret)" 1 "Beacon from another site refused (Origin $code, Sec-Fetch-Site $code2)"
[[ $code == 403 && $code2 == 403 ]] && pass '… with 403' || fail "… codes $code, $code2"
beacon -H "Origin: $home" >/dev/null & beacon -H "Origin: $home" >/dev/null & wait
expect returning "$(uid ret)" 2 'N21: two tabs send the beacon at once: one event, one run'
seen ret $((13 * 3600))
visit ret / & visit ret /shop/ & wait
expect returning "$(uid ret)" 3 'N21: two parallel page requests after 12 hours: one run'
seen ret $((13 * 3600))
code=$(beacon)
expect returning "$(uid ret)" 4 "Beacon without Origin or Sec-Fetch-Site is taken ($code)"

# Last added to cart: what counts as an add while signed in.
added() { phase added "$STATE" "$@" | sed -n 's/^ADDED //p' | tr -d '\r'; }
cart_api() { curl -s -b "$jars/$1" -c "$jars/$1" -D "$jars/$1.headers" "$home/wp-json/wc/store/v1/cart"; }
nonce_of() { sed -nE 's/^[Nn]once: *([^[:space:]]+).*/\1/p' "$jars/$1.headers" | tail -1; }
item_key() { php -r '$c = json_decode( $argv[1], true ); foreach ( $c["items"] ?? [] as $i ) { if ( (int) $i["id"] === (int) $argv[2] ) { echo $i["key"]; } }' "$(cart_api "$1")" "$2"; }
item_qty() { php -r '$c = json_decode( $argv[1], true ); foreach ( $c["items"] ?? [] as $i ) { if ( (int) $i["id"] === (int) $argv[2] ) { echo $i["quantity"]; } }' "$(cart_api "$1")" "$2"; }
store_api() { # jar, route, json
  cart_api "$1" >/dev/null
  curl -s -o /dev/null -b "$jars/$1" -c "$jars/$1" -H 'Content-Type: application/json' -H "Nonce: $(nonce_of "$1")" -d "$3" "$home/wp-json/wc/store/v1/cart/$2"
}
p1=$(json products 0)
p2=$(json products 1)
recent() { [[ $1 =~ ^[0-9]+$ ]] && (($(date +%s) - $1 < 120)); }
wp_login cart cart
visit cart /
[[ $(added cart) == none ]] && pass 'Cart: nothing added yet, no value' || fail "Cart: last added $(added cart) before any add"
visit cart "/?add-to-cart=$p1"
recent "$(added cart)" && pass 'N24: adding a product sets last added' || fail "N24: last added $(added cart)"
added cart 1 >/dev/null
store_api cart update-item "{\"key\":\"$(item_key cart "$p1")\",\"quantity\":2}"
recent "$(added cart)" && pass 'N25: a higher quantity through the Store API (block cart, mini-cart) counts' || fail "N25: Store API: last added $(added cart)"
added cart 1 >/dev/null
cartpage=$(json cartpage_url)
nonce=$(curl -s -b "$jars/cart" -c "$jars/cart" "$cartpage" | field woocommerce-cart-nonce)
curl -s -o /dev/null -b "$jars/cart" -c "$jars/cart" --data-urlencode "cart[$(item_key cart "$p1")][qty]=3" --data-urlencode "woocommerce-cart-nonce=$nonce" -d 'update_cart=Update+cart' "$cartpage"
[[ $(item_qty cart "$p1") == 3 ]] && recent "$(added cart)" && pass 'N25: a higher quantity on the classic Cart page counts' || fail "N25: Cart page: quantity $(item_qty cart "$p1"), last added $(added cart)"
visit cart "/?add-to-cart=$p2"
added cart 1 >/dev/null
store_api cart update-item "{\"key\":\"$(item_key cart "$p1")\",\"quantity\":1}"
store_api cart remove-item "{\"key\":\"$(item_key cart "$p2")\"}"
[[ $(item_qty cart "$p1") == 1 && -z $(item_qty cart "$p2") && $(added cart) == 1 ]] && pass 'G1: a lower quantity and one item removed, another left: not an add' || fail "G1: quantity $(item_qty cart "$p1"), other $(item_qty cart "$p2"), last added $(added cart)"
visit cart "/?add-to-cart=$p2"
store_api cart remove-item "{\"key\":\"$(item_key cart "$p2")\"}"
recent "$(added cart)" && pass 'N26: added then removed still counts as added' || fail "N26: last added $(added cart)"

# Signing in again merges the saved cart: not an add.
added cart 1 >/dev/null
wp_login cart2 cart
visit cart2 /cart/
[[ -n $(item_qty cart2 "$p1") && $(added cart) == 1 ]] && pass 'G2: the saved cart merged at sign-in is not an add' || fail "G2: cart quantity '$(item_qty cart2 "$p1")', last added $(added cart)"

# WooCommerce lowers a quantity itself when stock runs short: not an add.
store_api cart2 update-item "{\"key\":\"$(item_key cart2 "$p1")\",\"quantity\":5}"
added cart 1 >/dev/null
phase stock "$STATE" 2 >/dev/null
visit cart2 /cart/
visit cart2 /checkout/
[[ $(added cart) == 1 ]] && pass "G3: WooCommerce's stock check is not an add (quantity now $(item_qty cart2 "$p1"), stock 2)" || fail "G3: last added $(added cart)"
phase stock "$STATE" 10 >/dev/null

report "$(phase privacy "$STATE" cart)"

# The time tracking resumed: after reactivation and after the license comes back.
resumed() { "${WP[@]}" option get yaymatik_cart_tracking_resumed_at 2>/dev/null | tail -1 | tr -d '\r'; }
"${WP[@]}" option update yaymatik_cart_tracking_resumed_at 1000 >/dev/null 2>&1
"${WP[@]}" plugin deactivate "$PLUGIN_FOLDER" >/dev/null 2>&1
"${WP[@]}" plugin activate "$PLUGIN_FOLDER" >/dev/null 2>&1
visit anonymous /
recent "$(resumed)" && pass 'N28: deactivated and active again: tracking resumes now' || fail "N28: resumed at $(resumed)"
"${WP[@]}" option update yaymatik_cart_tracking_resumed_at 1000 >/dev/null 2>&1
phase edition lite >/dev/null
visit anonymous /
[[ $(resumed) == 1000 ]] && pass 'N29: without a license, nothing resumes' || fail "N29: resumed at $(resumed) without a license"
phase edition pro >/dev/null
visit anonymous /
recent "$(resumed)" && pass 'N29: license allowed again: tracking resumes now' || fail "N29: resumed at $(resumed)"

# The template, Logged in, nothing added to cart, through the engine: sign in, act while the run waits, then the hour is over.
out=$(phase template "$STATE")
report "$out"
flow=$(sed -n 's/^FLOW //p' <<<"$out" | tr -d '\r')
template_case() { # id, user, what, mails expected, then the action while waiting (a function name, or none)
  local result mails
  wp_login "$2" "$2"
  visit "$2" /
  [[ $5 != none ]] && "$5" "$2"
  out=$(phase finish "$STATE" "$2" "$flow")
  result=$(sed -n 's/^RESULT //p' <<<"$out" | tr -d '\r')
  mails=$(sed -n 's/^MAILS //p' <<<"$out" | tr -d '\r')
  if [[ $mails == "$4" && $result == completed:* ]]; then pass "$1: $3 ($result, $mails email)"; else fail "$1: $3 (run '$result', $mails emails, expected $4)"; fi
}
# Each action checks it happened, so a run that sends the email proves something.
did() { [[ $1 == "$2" ]] || fail "$3: the cart action didn't happen ($1, expected $2)"; }
add_p1() { visit "$1" "/?add-to-cart=$p1"; did "$(item_qty "$1" "$p1")" 1 add; }
raise_p1() { store_api "$1" update-item "{\"key\":\"$(item_key "$1" "$p1")\",\"quantity\":3}"; did "$(item_qty "$1" "$p1")" 3 raise; }
add_remove() { visit "$1" "/?add-to-cart=$p2"; did "$(item_qty "$1" "$p2")" 1 add; store_api "$1" remove-item "{\"key\":\"$(item_key "$1" "$p2")\"}"; did "$(item_qty "$1" "$p2")" '' remove; }
late_add() { phase finish "$STATE" "$1" "$flow" due >/dev/null; visit "$1" "/?add-to-cart=$p1"; }
reactivate() { "${WP[@]}" plugin deactivate "$PLUGIN_FOLDER" >/dev/null 2>&1; "${WP[@]}" plugin activate "$PLUGIN_FOLDER" >/dev/null 2>&1; visit anonymous /; }
license_gap() { phase edition lite >/dev/null; visit anonymous /; phase edition pro >/dev/null; visit anonymous /; }
lower_remove() {
  store_api "$1" update-item "{\"key\":\"$(item_key "$1" "$p1")\",\"quantity\":1}"
  store_api "$1" remove-item "{\"key\":\"$(item_key "$1" "$p2")\"}"
  did "$(item_qty "$1" "$p1")/$(item_qty "$1" "$p2")" 1/ 'lower and remove'
}
stock_fix() { phase stock "$STATE" 2 >/dev/null; visit "$1" /cart/; visit "$1" /checkout/; did "$(item_qty "$1" "$p1")" 2 'stock fix'; phase stock "$STATE" 10 >/dev/null; }

template_case T17 t17 'nothing added: the email goes after an hour' 1 none
phase saved "$STATE" t18 2,0 30 >/dev/null
template_case T18 t18 'items from before, the last one added earlier the same day, nothing added since: the email goes' 1 none
template_case N24 n24 'an item added while waiting: no email' 0 add_p1
phase saved "$STATE" n25 2,0 30 >/dev/null
template_case N25 n25 'a quantity raised while waiting: no email' 0 raise_p1
template_case N26 n26 'added, then all removed: no email' 0 add_remove
template_case N27 n27 'added after the hour, before the worker ran: no email' 0 late_add
template_case N28 n28 'YayMatik Pro deactivated and active again while waiting: no email' 0 reactivate
template_case N29 n29 'the license lapsed and came back while waiting: no email' 0 license_gap
phase saved "$STATE" g1 2,1 30 >/dev/null
template_case G1 g1 'a lower quantity and one item removed, another left: the email goes' 1 lower_remove
phase saved "$STATE" g2 1,1 30 >/dev/null
merged() { did "$(item_qty "$1" "$p1")/$(item_qty "$1" "$p2")" 1/1 'saved cart merged'; }
template_case G2 g2 'the saved cart merged at sign-in: the email goes' 1 merged
phase saved "$STATE" g3 5,0 30 >/dev/null
template_case G3 g3 "WooCommerce lowered a quantity for stock: the email goes" 1 stock_fix

echo
[[ $fails == 0 ]] && echo "All checks passed." || echo "$fails check(s) failed."
[[ $fails == 0 ]]
