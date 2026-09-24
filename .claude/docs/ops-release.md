# Ops: Release

Use `/wdist`.

- Version: `yay-automation.php` header `Version:` + `YAY_AUTOMATION_VERSION` — keep in sync.
- Zip: `bash release.sh` → `release/yay-automation.zip`. Exclusions: `.distignore`.
- Before release: build passes, clean working tree, zip loads the admin page on a clean site.
