#!/bin/bash
# Fails when the free zip holds YayMatik Pro: includes/Pro, or a Pro template id or `yaymatik-pro` in assets/dist.
#   dev/scripts/lib/check-lite-zip.sh release/yaymatik.zip   (run by release.sh for the free edition)
cd "$(dirname "$0")/../../.." || exit 1
zip=$1
[[ -f $zip ]] || { echo "No zip: $zip"; exit 1; }

# One source: the ids in the Pro folder itself.
ids=$(grep -rhoE "^  id: '[a-z0-9-]+'" apps/admin-page/src/pro --include=index.ts | sed -E "s/.*'(.*)'/\1/")
words=(yaymatik-pro $ids)

found=()
unzip -Z1 "$zip" | grep -q '^yaymatik/includes/Pro/' && found+=("includes/Pro")
tmp=$(mktemp -d) || exit 1
trap 'rm -rf "$tmp"' EXIT
unzip -q "$zip" 'yaymatik/assets/dist/*' -d "$tmp"
for word in "${words[@]}"; do
    # Quoted, as the bundle holds ids as strings: `cart-series` is also part of a longer id's text.
    grep -rqE "[\"'\`]$word[\"'\`]|$word/" "$tmp/yaymatik/assets/dist" && found+=("$word in assets/dist")
done

if [[ ${#found[@]} -gt 0 ]]; then
    printf 'Lite zip check failed, YayMatik Pro found:\n'
    printf '  %s\n' "${found[@]}"
    exit 1
fi
echo "Lite zip check: no Pro code (${#words[@]} ids checked)."
