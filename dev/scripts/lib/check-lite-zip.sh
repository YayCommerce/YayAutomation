#!/bin/bash
# Fails when the free zip holds YayMatik Pro: includes/Pro, or a Pro step id, a Pro template id or `yaymatik-pro` in assets/dist.
#   dev/scripts/lib/check-lite-zip.sh release/yaymatik.zip   (run by release.sh for the free edition)
cd "$(dirname "$0")/../../.." || exit 1
zip=$1
[[ -f $zip ]] || { echo "No zip: $zip"; exit 1; }

# One source each: ProCatalog for step ids, the Pro folder's template definitions for template ids.
steps=$(php -r 'define( "ABSPATH", "/" ); require "includes/Workflow/Core/Definition/ProCatalog.php"; echo implode( " ", array_keys( YayMatik\Workflow\Core\Definition\ProCatalog::STEPS ) );') || exit 1
templates=$(grep -rhoE "^  id: '[a-z0-9-]+'" apps/admin-page/src/pro --include=index.ts | sed -E "s/.*'(.*)'/\1/")
words=(yaymatik-pro $templates)

found=()
unzip -Z1 "$zip" | grep -q '^yaymatik/includes/Pro/' && found+=("includes/Pro")
tmp=$(mktemp -d) || exit 1
trap 'rm -rf "$tmp"' EXIT
unzip -q "$zip" 'yaymatik/assets/dist/*' -d "$tmp"
for word in "${words[@]}"; do
    # Quoted: `cart-series` is also the start of a longer template id.
    grep -rqE "[\"'\`]$word[\"'\`]|$word/" "$tmp/yaymatik/assets/dist" && found+=("$word in assets/dist")
done
for step in $steps; do
    # A whole word: minified object keys aren't quoted.
    grep -rqwF "$step" "$tmp/yaymatik/assets/dist" && found+=("$step in assets/dist")
done

if [[ ${#found[@]} -gt 0 ]]; then
    printf 'Lite zip check failed, YayMatik Pro found:\n'
    printf '  %s\n' "${found[@]}"
    exit 1
fi
echo "Lite zip check: no Pro code ($(( ${#words[@]} + $(wc -w <<< "$steps") )) names checked)."
