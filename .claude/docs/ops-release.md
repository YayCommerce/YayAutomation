# Ops: Release

Use `/wdist`.

- Version: `yaymatik.php` header `Version:` + `YAYMATIK_VERSION` — keep in sync.
- Zip: `./run.sh release` (`dev/scripts/release.sh`) → `release/yaymatik.zip`. Exclusions: `.distignore`. It runs `./vendor/bin/yaycommerce-prerelease` first and ships only `vendor/autoload.php` + `vendor/composer/` + `vendor-prefixed/`.
- Before release: build passes, clean working tree, zip loads the admin page on a clean site.
