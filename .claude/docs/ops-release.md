# Ops: Release

Use `/wdist`.

- Version: `yay-automation.php` header `Version:` + `YAY_AUTOMATION_VERSION` — keep in sync.
- Zip: `./run.sh release` (`dev/scripts/release.sh`) → `release/yay-automation.zip`. Exclusions: `.distignore`. It runs `./vendor/bin/yaycommerce-prerelease` first and ships only `vendor/autoload.php` + `vendor/composer/` + `vendor-prefixed/`.
- Before release: build passes, clean working tree, zip loads the admin page on a clean site.
