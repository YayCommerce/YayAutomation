#!/bin/bash
# Build the distribution zip: release/yay-automation.zip
cd "$(dirname "$0")/../.." || exit 1
[[ -f yay-automation.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }

PLUGIN_SLUG="yay-automation"
PROJECT_PATH=$(pwd)
BUILD_PATH="${PROJECT_PATH}/release"
DEST_PATH="$BUILD_PATH/$PLUGIN_SLUG"

if [[ ! -f vendor/autoload.php ]]; then
    echo "Installing Composer dependencies..."
    composer install || exit 1
fi

echo "Rebuilding scoped admin-shell..."
./vendor/bin/yaycommerce-prerelease || exit 1

echo "Generating build directory..."
[[ -n $PLUGIN_SLUG && -d $BUILD_PATH ]] && rm -rf "$BUILD_PATH"
mkdir -p "$DEST_PATH"

echo "Installing JS dependencies..."
pnpm install --frozen-lockfile
echo "Running JS Build..."
pnpm build || exit 1

echo "Syncing files..."
rsync -rc --exclude-from="$PROJECT_PATH/.distignore" "$PROJECT_PATH/" "$DEST_PATH/" --delete --delete-excluded

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
sed -i "" "/'YAY_AUTOMATION_IS_DEVELOPMENT', true/d" "$DEST_PATH/yay-automation.php"
rm -f "$DEST_PATH/includes/Engine/Register/RegisterDev.php"

echo "Generating zip file..."
cd "$BUILD_PATH" || exit
zip -q -r "${PLUGIN_SLUG}.zip" "$PLUGIN_SLUG/"
rm -rf "$PLUGIN_SLUG"
echo "${PLUGIN_SLUG}.zip file generated!"

echo "Build successfully"
