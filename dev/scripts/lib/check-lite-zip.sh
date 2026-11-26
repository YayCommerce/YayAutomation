#!/bin/bash
# Fails when the free zip holds YayMatik Pro: includes/Pro, or a Pro step id, a Pro template id or `yaymatik-pro` in assets/dist;
# while the teaser is hidden, also its text or link in assets/dist or the .pot; when its classmap names RegisterDev; and when a
# shipped file names a license, a trial or an upsell outside dev/wporg/word-allowlist.txt (wporg-words.php).
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

words_out=$(php dev/scripts/lib/wporg-words.php "$tmp/$WPORG_SLUG" dev/wporg/word-allowlist.txt) || found+=("$words_out")

if [[ ${#found[@]} -gt 0 ]]; then
    printf 'Lite zip check failed, found:\n'
    printf '  %s\n' "${found[@]}"
    exit 1
fi
echo "Lite zip check: no Pro code ($(( ${#words[@]} + $(wc -w <<< "$steps") )) names checked). $words_out"
