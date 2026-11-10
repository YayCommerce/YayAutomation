#!/bin/bash
# Build the distribution zip: release/yaymatik.zip, or with `pro` release/yaymatik-pro.zip (includes/Pro kept). --rc stamps X.Y.Z-rcN in the zip copy.
set -eo pipefail
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }

EDITION=free
RC=false
for arg in "$@"; do
    case $arg in
        free | pro) EDITION=$arg ;;
        --rc) RC=true ;;
        *) echo "Usage: ./run.sh release [pro] [--rc]"; exit 1 ;;
    esac
done
PLUGIN_SLUG="yaymatik"
ZIP_NAME=$PLUGIN_SLUG
[[ $EDITION == pro ]] && ZIP_NAME="$PLUGIN_SLUG-pro"
PROJECT_PATH=$(pwd)
BUILD_PATH="${PROJECT_PATH}/release"
DEST_PATH="$BUILD_PATH/$PLUGIN_SLUG"

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
pnpm build || exit 1

echo "Syncing files..."
EXCLUDES="$PROJECT_PATH/.distignore"
if [[ $EDITION == pro ]]; then
    EXCLUDES=$(mktemp)
    trap 'rm -f "$EXCLUDES"' EXIT
    grep -vx '/includes/Pro' "$PROJECT_PATH/.distignore" > "$EXCLUDES"
fi
rsync -rc --exclude-from="$EXCLUDES" "$PROJECT_PATH/" "$DEST_PATH/" --delete --delete-excluded

# WordPress.org allows no license client or updater in the free plugin, so its zip leaves out admin-shell's and the Pro adapter.
if [[ $EDITION == free ]]; then
    rm -rf "$DEST_PATH/vendor-prefixed/src/License"
    rm -f "$DEST_PATH/vendor-prefixed/views/"{license-card,license-activate-card,licenses-page}.php "$DEST_PATH/includes/YayMatikProLicenseAdapter.php"
fi

# Ship only the runtime autoloader: drop dev packages + unscoped admin-shell, rebuild a no-dev classmap.
find "$DEST_PATH/vendor" -mindepth 1 -maxdepth 1 ! -name autoload.php ! -name composer -exec rm -rf {} +
cp "$PROJECT_PATH/composer.json" "$PROJECT_PATH/composer.lock" "$DEST_PATH/"
composer dump-autoload --optimize --classmap-authoritative --no-dev --working-dir="$DEST_PATH" || exit 1
rm -f "$DEST_PATH/composer.json" "$DEST_PATH/composer.lock"

# Same pinned tools as `pnpm lint:php` (installed by ./run.sh setup).
PHPCBF="$PROJECT_PATH/dev/tools/lintphp/vendor/bin/phpcbf"
if [[ -x $PHPCBF ]]; then
    echo "Run lint"
    "$PHPCBF" --standard="$PROJECT_PATH/phpcs.xml" "$DEST_PATH" || true
else
    echo "Skipping lint: run ./run.sh setup to install the PHP lint tools."
fi

# Remove development code
# -i.bak works with BSD (macOS) and GNU sed alike.
sed -i.bak "/'YAYMATIK_IS_DEVELOPMENT', true/d" "$DEST_PATH/yaymatik.php" && rm "$DEST_PATH/yaymatik.php.bak"
rm -f "$DEST_PATH/includes/Engine/Register/RegisterDev.php"

# main.js has a fixed name, so only a new ?ver=YAYMATIK_VERSION makes browsers load a new build.
if $RC; then
    STAMP="$(sed -n "s/.*'YAYMATIK_VERSION', '\([^']*\)'.*/\1/p" yaymatik.php)-rc$(git rev-list --count HEAD)"
    sed -i.bak -E "s/^( \* Version: +).*/\1$STAMP/; s/('YAYMATIK_VERSION', ')[^']*/\1$STAMP/" "$DEST_PATH/yaymatik.php" && rm "$DEST_PATH/yaymatik.php.bak"
    [[ $(grep -c -F -- "$STAMP" "$DEST_PATH/yaymatik.php") == 2 ]] || { echo "Could not stamp $STAMP in yaymatik.php"; exit 1; }
    echo "Stamped $STAMP"
fi

echo "Generating zip file..."
cd "$BUILD_PATH" || exit
zip -q -r "${ZIP_NAME}.zip" "$PLUGIN_SLUG/"
rm -rf "$PLUGIN_SLUG"
echo "${ZIP_NAME}.zip file generated!"

echo "Build successfully"
