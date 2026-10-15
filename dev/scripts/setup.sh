#!/bin/bash
# Install PHP dependencies: vendor/autoload.php + vendor-prefixed/ (scoped admin-shell) + lint and test tools.
cd "$(dirname "$0")/../.." || exit 1
[[ -f yaymatik.php ]] || { echo "Not the plugin root: $(pwd)"; exit 1; }
command -v composer >/dev/null || { echo "Missing: composer"; exit 1; }

# composer.json classmaps vendor-prefixed/src, so it must exist before install.
mkdir -p vendor-prefixed/src

echo "Installing Composer dependencies..."
composer install || {
  echo "composer install failed. It needs GitHub access to YayCommerce/yaycommerce-plugin-admin-shell."
  exit 1
}

# Same build as vendor/bin/yaycommerce-update, minus its package upgrades.
echo "Building scoped admin-shell..."
rm -rf vendor-prefixed
./vendor/bin/php-scoper add-prefix --output-dir=vendor-prefixed --force || exit 1
cp -r vendor/yaycommerce/admin-shell/assets vendor-prefixed/assets
# Not authoritative: new includes/ classes resolve via PSR-4 without a re-dump.
composer dump-autoload || exit 1

# Dev-only tools, kept out of the plugin's composer.json so they never ship.
echo "Installing PHP lint tools..."
composer install -d dev/tools/lintphp || exit 1
echo "Installing PHP test tools..."
composer install -d dev/tools/phpunit || exit 1

echo "Setup done."
