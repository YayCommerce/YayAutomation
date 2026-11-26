#!/bin/bash
# Build the distribution zip: release/yaymatik-automation-for-woocommerce.zip (folder = the WordPress.org slug), or with `pro` release/yaymatik-pro.zip (folder yaymatik-pro, includes/Pro kept). --rc stamps X.Y.Z-rcN in the zip copy.
set -eo pipefail
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
source dev/scripts/lib/wporg.sh

EDITION=free
RC=false
for arg in "$@"; do
    case $arg in
        free | pro) EDITION=$arg ;;
        --rc) RC=true ;;
        *) echo "Usage: ./run.sh release [pro] [--rc]"; exit 1 ;;
    esac
done
# Pro is its own plugin: folder and main file yaymatik-pro, so it installs beside the free one (Fallback.php deactivates the second).
PLUGIN_SLUG=$WPORG_SLUG
MAIN_FILE="yaymatik.php"
if [[ $EDITION == pro ]]; then
    PLUGIN_SLUG="yaymatik-pro"
    MAIN_FILE="yaymatik-pro.php"
fi
ZIP_NAME=$PLUGIN_SLUG
PROJECT_PATH=$(pwd)
BUILD_PATH="${PROJECT_PATH}/release"
DEST_PATH="$BUILD_PATH/$PLUGIN_SLUG"

# One EXIT trap for both editions (a second `trap … EXIT` replaces the first): it puts the checkout back however the release ends.
LITE_BUILT=false
EXCLUDES_TMP=""
cleanup() {
    cd "$PROJECT_PATH" || return
    [[ -n $EXCLUDES_TMP ]] && rm -f "$EXCLUDES_TMP"
    # The checkout runs the Pro code.
    if $LITE_BUILT; then
        echo "Restoring the Pro build in assets/dist..."
        pnpm build >/dev/null
    fi
    # yaycommerce-prerelease leaves the checkout's classmap authoritative: a new class in includes/ wouldn't load.
    [[ -f vendor/autoload.php ]] && composer dump-autoload -q
}
trap cleanup EXIT

# The license item id is a stand-in until YayMatik Pro has its own: only test builds may ship it.
if [[ $EDITION == pro ]] && ! $RC && grep -qE 'TODO\(036\)|ITEM_ID_IS_TEMPORARY = true' includes/Pro/YayMatikProLicenseAdapter.php; then
    echo "includes/Pro/YayMatikProLicenseAdapter.php still has the temporary item id (TODO(036), ITEM_ID_IS_TEMPORARY): use --rc for a test build."
    exit 1
fi

header_value() { sed -nE "s/^[ *]*$1: *([^ ]*).*/\1/p" "$2" | head -1; }
bad=()
for file in yaymatik.php readme.txt; do
    for name in 'Requires at least' 'Tested up to' 'WC requires at least' 'WC tested up to'; do
        value=$(header_value "$name" "$file")
        [[ $value =~ ^[0-9]+\.[0-9]+\.[0-9]+ ]] && bad+=("$file $name: $value (major.minor only, e.g. 11.3)")
    done
done
version=$(header_value Version yaymatik.php)
constant=$(sed -nE "s/.*'YAYMATIK_VERSION', '([^']*)'.*/\1/p" yaymatik.php)
stable=$(header_value 'Stable tag' readme.txt)
for value in "$version" "$constant" "$stable"; do
    [[ $value =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || bad+=("Plugin version '$value' is not x.y.z")
done
[[ $version == "$constant" && $version == "$stable" ]] || bad+=("Version ($version), YAYMATIK_VERSION ($constant) and Stable tag ($stable) differ")
if [[ ${#bad[@]} -gt 0 ]]; then
    printf 'Version headers:\n'
    printf '  %s\n' "${bad[@]}"
    exit 1
fi

# Lint the source, not the build: a path argument bypasses phpcs.xml's <file> list and would reach
# vendor-prefixed after its patches were verified.
if [[ $YAYMATIK_SKIP_LINT == 1 ]]; then
    echo "Lint skipped: done by the caller"
else
    [[ -x dev/tools/lintphp/vendor/bin/phpcs ]] || { echo "Missing PHP lint tools: run ./run.sh setup"; exit 1; }
    echo "Linting PHP..."
    pnpm lint:php || exit 1
fi

if [[ ! -f vendor/autoload.php ]]; then
    echo "Installing Composer dependencies..."
    # composer.json classmaps vendor-prefixed/src, so it must exist before install.
    mkdir -p vendor-prefixed/src
    composer install || exit 1
fi

echo "Rebuilding scoped admin-shell..."
./vendor/bin/yaycommerce-prerelease || exit 1

echo "Generating build directory..."
# Only this edition's output: the other edition's zip stays.
[[ -n $PLUGIN_SLUG ]] && rm -rf "$DEST_PATH" "$BUILD_PATH/$ZIP_NAME.zip"
mkdir -p "$DEST_PATH"

echo "Installing JS dependencies..."
pnpm install --frozen-lockfile
echo "Running JS Build..."
[[ $EDITION == free ]] && LITE_BUILT=true
YAYMATIK_EDITION=$([[ $EDITION == pro ]] && echo pro || echo lite) pnpm build || exit 1

echo "Syncing files..."
EXCLUDES="$PROJECT_PATH/.distignore"
if [[ $EDITION == pro ]]; then
    EXCLUDES_TMP=$(mktemp)
    EXCLUDES=$EXCLUDES_TMP
    grep -vx '/includes/Pro' "$PROJECT_PATH/.distignore" > "$EXCLUDES"
fi
rsync -rc --exclude-from="$EXCLUDES" "$PROJECT_PATH/" "$DEST_PATH/" --delete --delete-excluded
if [[ $EDITION == pro ]]; then
    mv "$DEST_PATH/yaymatik.php" "$DEST_PATH/$MAIN_FILE"
    # Update URI: WordPress.org never offers its plugin of the same slug as an update.
    sed -i.bak -E 's/^( \* Plugin Name: +).*/\1YayMatik Pro/; s#^( \* Requires Plugins: +.*)#\1\n * Update URI:        https://yaycommerce.com/yaymatik-pro/#' "$DEST_PATH/$MAIN_FILE"
    rm "$DEST_PATH/$MAIN_FILE.bak"
    [[ $(grep -cE '^ \* (Plugin Name: +YayMatik Pro|Update URI: )' "$DEST_PATH/$MAIN_FILE") == 2 ]] || { echo "Could not write the Pro header in $MAIN_FILE"; exit 1; }
fi

# WordPress.org allows no license client, updater or license page in the free plugin: admin-shell's src/License goes,
# and free-admin-shell.php strips the rest of its license code and hardens its installer (see the script). Its strings
# take the plugin's text domain: one domain per zip. Both editions get the ABSPATH guard in every admin-shell file.
if [[ $EDITION == free ]]; then
    rm -rf "$DEST_PATH/vendor-prefixed/src/License"
    php "$PROJECT_PATH/dev/scripts/lib/free-admin-shell.php" "$DEST_PATH/vendor-prefixed" || exit 1
    php "$PROJECT_PATH/dev/scripts/lib/rename-text-domain.php" yaycommerce "$WPORG_SLUG" "$DEST_PATH/vendor-prefixed" >/dev/null || exit 1
else
    php "$PROJECT_PATH/dev/scripts/lib/free-admin-shell.php" "$DEST_PATH/vendor-prefixed" --abspath-only || exit 1
fi

# Ship only the runtime autoloader: drop dev packages + unscoped admin-shell, rebuild a no-dev classmap.
# composer.json ships (Plugin Check asks for it next to vendor/); the lock is only for the dump.
# Development code goes first: the classmap must not name it.
rm -f "$DEST_PATH/includes/Engine/Register/RegisterDev.php"
find "$DEST_PATH/vendor" -mindepth 1 -maxdepth 1 ! -name autoload.php ! -name composer -exec rm -rf {} +
cp "$PROJECT_PATH/composer.lock" "$DEST_PATH/"
composer dump-autoload --optimize --classmap-authoritative --no-dev --working-dir="$DEST_PATH" || exit 1
rm -f "$DEST_PATH/composer.lock"
# The free zip's check (check-lite-zip.sh) has the same test.
if [[ $EDITION == pro ]] && grep -q RegisterDev "$DEST_PATH/vendor/composer/autoload_classmap.php"; then
    echo "The classmap names RegisterDev, which the zip leaves out."
    exit 1
fi

# main.js has a fixed name, so only a new ?ver=YAYMATIK_VERSION makes browsers load a new build.
if $RC; then
    STAMP="$(sed -n "s/.*'YAYMATIK_VERSION', '\([^']*\)'.*/\1/p" yaymatik.php)-rc$(git rev-list --count HEAD)"
    sed -i.bak -E "s/^( \* Version: +).*/\1$STAMP/; s/('YAYMATIK_VERSION', ')[^']*/\1$STAMP/" "$DEST_PATH/$MAIN_FILE"
    rm "$DEST_PATH/$MAIN_FILE.bak"
    [[ $(grep -c -F -- "$STAMP" "$DEST_PATH/$MAIN_FILE") == 2 ]] || { echo "Could not stamp $STAMP in $MAIN_FILE"; exit 1; }
    echo "Stamped $STAMP"
fi

# The translation template from the files that ship (after the rc stamp): WordPress.org builds the language packs from it. Written into the
# build folder only, as its creation date would leave the checkout changed after every release.
command -v wp >/dev/null || { echo "Missing: wp-cli (wp i18n make-pot)"; exit 1; }
wp i18n make-pot "$DEST_PATH" "$DEST_PATH/languages/$WPORG_SLUG.pot" --include="$MAIN_FILE",includes,assets/dist --domain="$WPORG_SLUG" --slug="$WPORG_SLUG"

echo "Generating zip file..."
cd "$BUILD_PATH" || exit
zip -q -r "${ZIP_NAME}.zip" "$PLUGIN_SLUG/"
rm -rf "$PLUGIN_SLUG"
echo "${ZIP_NAME}.zip file generated!"
cd "$PROJECT_PATH" || exit 1

if [[ $EDITION == free ]]; then
    dev/scripts/lib/check-lite-zip.sh "release/$ZIP_NAME.zip" || { rm -f "release/$ZIP_NAME.zip"; exit 1; }
fi

echo "Build successfully"
