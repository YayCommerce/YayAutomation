#!/bin/bash
# Fails when the free zip holds YayMatik Pro: includes/Pro, or a Pro step id, a Pro template id or `yaymatik-pro` in assets/dist;
# while the teaser is hidden, also its text or link in assets/dist or the .pot; when its classmap names RegisterDev; and when a
# shipped file names a license, a trial or an upsell outside dev/wporg/word-allowlist.txt (wporg-words.php); when admin-shell
# still ships license code or its URL installer; and when a gettext call uses another text domain than the slug.
#   dev/scripts/lib/check-lite-zip.sh release/yaymatik-automation-for-woocommerce.zip   (run by release.sh for the free edition)
cd "$(dirname "$0")/../../.." || exit 1
source dev/scripts/lib/wporg.sh
zip=$1
[[ -f $zip ]] || { echo "No zip: $zip"; exit 1; }

# One source each: ProCatalog for step ids, the Pro folder's template definitions for template ids.
steps=$(php -r 'define( "ABSPATH", "/" ); require "includes/Workflow/Core/Definition/ProCatalog.php"; echo implode( " ", array_keys( YayMatik\Workflow\Core\Definition\ProCatalog::STEPS ) );') || exit 1
templates=$(grep -rhoE "^  id: '[a-z0-9-]+'" apps/admin-page/src/pro --include=index.ts | sed -E "s/.*'(.*)'/\1/")
words=(yaymatik-pro $templates)

found=()
unzip -Z1 "$zip" | grep -q "^$WPORG_SLUG/includes/Pro/" && found+=("includes/Pro")
# The zip leaves RegisterDev.php out: a classmap naming it breaks a site with YAYMATIK_IS_DEVELOPMENT.
unzip -p "$zip" "$WPORG_SLUG/vendor/composer/autoload_classmap.php" | grep -q RegisterDev && found+=("RegisterDev in the classmap")
tmp=$(mktemp -d) || exit 1
trap 'rm -rf "$tmp"' EXIT
unzip -q "$zip" -d "$tmp"
for word in "${words[@]}"; do
    # Quoted: `cart-series` is also the start of a longer template id.
    grep -rqE "[\"'\`]$word[\"'\`]|$word/" "$tmp/$WPORG_SLUG/assets/dist" && found+=("$word in assets/dist")
done
for step in $steps; do
    # A whole word: minified object keys aren't quoted.
    grep -rqwF "$step" "$tmp/$WPORG_SLUG/assets/dist" && found+=("$step in assets/dist")
done

# Remove with the revert of the hidden teaser (src/lite/teaser.ts).
TEASER_HIDDEN=true
if $TEASER_HIDDEN; then
    # Without the plugin header's Plugin URI: the same address as the teaser's link.
    sed '/^#\. Plugin URI of the plugin$/,/^msgstr/d' "$tmp/$WPORG_SLUG/languages/$WPORG_SLUG.pot" > "$tmp/pot"
    for text in 'Get YayMatik Pro' 'yaycommerce.com/yaymatik'; do
        grep -rqF "$text" "$tmp/$WPORG_SLUG/assets/dist" "$tmp/pot" && found+=("$text in assets/dist or the .pot")
    done
fi

# admin-shell as free-admin-shell.php leaves it.
# Composer's own MIT notice for its autoloader is the one license file the zip ships.
unzip -Z1 "$zip" | grep -iE 'icens[^/]*$' | grep -vx "$WPORG_SLUG/vendor/composer/LICENSE" > "$tmp/icens"
[[ -s $tmp/icens ]] && found+=("license files: $(tr '\n' ' ' < "$tmp/icens")")
for text in LegacyBridge AddonBridge enable_license go-pro '->install($plugin' 'install($_POST'; do
    grep -rqF -- "$text" "$tmp/$WPORG_SLUG" && found+=("$text in the zip")
done
# The same count with and without the domain filter: every string in the zip is in the slug's domain.
command -v wp >/dev/null || { echo "Missing: wp-cli (wp i18n make-pot)"; exit 1; }
count_strings() { wp i18n make-pot "$tmp/$WPORG_SLUG" "$tmp/count.pot" --skip-audit "$@" >/dev/null 2>&1 && grep -c '^msgid "' "$tmp/count.pot"; }
in_slug=$(count_strings --domain="$WPORG_SLUG")
in_any=$(count_strings --ignore-domain)
[[ -n $in_slug && $in_slug == "$in_any" ]] || found+=("gettext strings: $in_any in any text domain, ${in_slug:-?} in $WPORG_SLUG")
# make-pot merges equal strings across domains: phpcs names each PHP call in another domain.
mismatch=$(dev/tools/lintphp/vendor/bin/phpcs --standard=WordPress --sniffs=WordPress.WP.I18n --runtime-set text_domain "$WPORG_SLUG" \
    --extensions=php --ignore='*/vendor/*' --report=csv -q "$tmp/$WPORG_SLUG" | grep TextDomainMismatch)
[[ -z $mismatch ]] || found+=("gettext calls in another text domain:"$'\n'"$mismatch")
shell_version=$(sed -nE "s/.*const VERSION = '([^']+)'.*/\1/p" "$tmp/$WPORG_SLUG/vendor-prefixed/src/AdminShell.php")
grep -qF "(\`yaycommerce/admin-shell\`) $shell_version," "$tmp/$WPORG_SLUG/readme.txt" || found+=("readme.txt: the admin-shell version isn't $shell_version")

words_out=$(php dev/scripts/lib/wporg-words.php "$tmp/$WPORG_SLUG" dev/wporg/word-allowlist.txt) || found+=("$words_out")

if [[ ${#found[@]} -gt 0 ]]; then
    printf 'Lite zip check failed, found:\n'
    printf '  %s\n' "${found[@]}"
    exit 1
fi
echo "Lite zip check: no Pro code ($(( ${#words[@]} + $(wc -w <<< "$steps") )) names checked), no license code, $in_slug strings all in $WPORG_SLUG. $words_out"
