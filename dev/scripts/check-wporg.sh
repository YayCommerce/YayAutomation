#!/bin/bash
# Check the free zip against the WordPress.org review rules (text domain, words, edition limits, nonces, license and installer code, URLs, readme).
#   ./run.sh check-wporg release/yaymatik-automation-for-woocommerce.zip [--urls]   (release.sh runs it without --urls; /wp-release with)
# --urls also asks every URL in the plugin header and readme.txt for a 2xx answer, logged out (network).
[[ -f $1 ]] || { echo "Usage: ./run.sh check-wporg <zip> [--urls]"; exit 1; }
zip=$(cd "$(dirname "$1")" && pwd)/$(basename "$1")
cd "$(dirname "$0")/../.." || exit 1
source dev/scripts/lib/wporg.sh
urls=false
[[ $2 == --urls ]] && urls=true
command -v wp >/dev/null || { echo "Missing: wp-cli (wp i18n make-pot)"; exit 1; }

tmp=$(mktemp -d) || exit 1
trap 'rm -rf "$tmp"' EXIT
unzip -q "$zip" -d "$tmp"
dir=$tmp/$WPORG_SLUG
found=()
pass=()

# 1. Text domain = slug: the zip's only top folder, the header, every gettext call.
top=$(unzip -Z1 "$zip" | cut -d/ -f1 | sort -u | tr '\n' ' ')
[[ $top == "$WPORG_SLUG " ]] || found+=("1 the zip's top folder is '${top% }', not $WPORG_SLUG")
domain=$(sed -nE 's/^[ *]*Text Domain: *([^ ]+).*/\1/p' "$dir/yaymatik.php" 2>/dev/null)
[[ $domain == "$WPORG_SLUG" ]] || found+=("1 header Text Domain is '$domain', not $WPORG_SLUG")
count_strings() { wp i18n make-pot "$dir" "$tmp/count.pot" --skip-audit "$@" >/dev/null 2>&1 && grep -c '^msgid "' "$tmp/count.pot"; }
in_slug=$(count_strings --domain="$WPORG_SLUG")
in_any=$(count_strings --ignore-domain)
[[ -n $in_slug && $in_slug == "$in_any" ]] || found+=("1 gettext strings: $in_any in any text domain, ${in_slug:-?} in $WPORG_SLUG")
# make-pot merges equal strings across domains: phpcs names each PHP call in another domain.
mismatch=$(dev/tools/lintphp/vendor/bin/phpcs --standard=WordPress --sniffs=WordPress.WP.I18n --runtime-set text_domain "$WPORG_SLUG" \
    --extensions=php --ignore='*/vendor/*' --report=csv -q "$dir" | grep TextDomainMismatch)
[[ -z $mismatch ]] || found+=("1 gettext calls in another text domain:"$'\n'"$mismatch")
pass+=("1 text domain: folder, header and $in_slug strings in $WPORG_SLUG")

# 2. No license, trial or upsell words.
out=$(php dev/scripts/lib/wporg-words.php "$dir" dev/wporg/word-allowlist.txt) && pass+=("2 $out") || found+=("2 $out")

# 3, 4 and the installer half of 5: edition limits, nonces, where the installer lives.
out=$(php dev/scripts/lib/wporg-code.php "$dir" dev/wporg/nonce-exceptions.txt dev/wporg/edition-allowlist.txt) && pass+=("3-5 $out") || found+=("3-5 $out")

# 5. No license client, updater or license page (admin-shell as free-admin-shell.php leaves it).
# Composer's own MIT notice for its autoloader is the one license file the zip ships.
icens=$(unzip -Z1 "$zip" | grep -iE 'icens[^/]*$' | grep -vx "$WPORG_SLUG/vendor/composer/LICENSE" | tr '\n' ' ')
[[ -z $icens ]] || found+=("5 license files: $icens")
# The installer's place is gate 5 in wporg-code.php.
for text in EDD_SL pre_set_site_transient_update_plugins LegacyBridge AddonBridge enable_license go-pro; do
    grep -rqF -- "$text" "$dir" && found+=("5 '$text' in the zip")
done
shell_version=$(sed -nE "s/.*const VERSION = '([^']+)'.*/\1/p" "$dir/vendor-prefixed/src/AdminShell.php")
grep -qF "(\`yaycommerce/admin-shell\`) $shell_version," "$dir/readme.txt" || found+=("5 readme.txt: the admin-shell version isn't $shell_version")
pass+=("5 no license files, updater or license code; readme names admin-shell $shell_version")

# 6. Every URL in the plugin header and the readme answers 2xx, logged out, after redirects.
if $urls; then
    list=$( { sed -nE 's/^[ *]*(Plugin|Author) URI: *(https?:[^ ]+).*/\2/p' "$dir/yaymatik.php"; grep -oE 'https?://[^ )>`"*]+' "$dir/readme.txt"; } | sed -E 's/[.,;:]+$//' | sort -u)
    checked=0
    for url in $list; do
        code=$(curl -sIL -o /dev/null -w '%{http_code}' --max-time 30 "$url")
        # A server that refuses HEAD gets one GET.
        [[ $code == 2* ]] || code=$(curl -sL -o /dev/null -w '%{http_code}' --max-time 30 "$url")
        [[ $code == 2* ]] || found+=("6 $url answers $code")
        checked=$((checked + 1))
    done
    pass+=("6 $checked URLs answer 2xx")
else
    pass+=("6 URLs not checked (--urls)")
fi

# 7. The readme's External services section matches the code: no call to another server but WordPress.org's own plugin API
# (the Recommended Plugins page, on the admin's request) while it says the plugin doesn't connect to any.
if grep -q "doesn't connect to any external service" "$dir/readme.txt"; then
    calls=$(grep -rnE "wp_(safe_)?remote_[a-z_]+\(|\bplugins_api\(|\bcurl_exec\(|\bdownload_url\(|\bWP_Http\b|(file_get_contents|fopen)\(\s*['\"]https?:" --include='*.php' "$dir" | grep -v "/vendor-prefixed/src/Pages/RecommendedPluginsPage.php:.*plugins_api(" | sed "s#$dir/##")
    remote_fetch=$(grep -rnoE "fetch\(\s*[\"'\`]https?://" "$dir/assets" | sed "s#$dir/##")
    [[ -z $calls$remote_fetch ]] || found+=("7 readme says no external service, the code calls one:"$'\n'"$calls$remote_fetch")
    pass+=("7 readme: no external service, none called")
else
    found+=("7 readme.txt: External services changed; review this gate against it")
fi

if [[ ${#found[@]} -gt 0 ]]; then
    printf 'WordPress.org check failed:\n'
    printf '  %s\n' "${found[@]}"
    exit 1
fi
printf 'WordPress.org check passed:\n'
printf '  %s\n' "${pass[@]}"
