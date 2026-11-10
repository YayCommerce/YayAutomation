# Ops: Release

Use `/wdist`.

- Version: `yaymatik.php` header `Version:` + `YAYMATIK_VERSION` — keep in sync.
- Zip: `./run.sh release` (`dev/scripts/release.sh`) → `release/yaymatik.zip` (free: `.distignore` leaves out `includes/Pro/`). `./run.sh release pro` → `release/yaymatik-pro.zip`, the same with `includes/Pro/`. Exclusions: `.distignore`. It runs `./vendor/bin/yaycommerce-prerelease` first and ships only `vendor/autoload.php` + `vendor/composer/` + `vendor-prefixed/`.
- WordPress.org review: `/wp-review` checks the free zip against `.claude/docs/context-wporg-release-checklist.md` (IDs S, G, B, Q, R, T, U).
- Before release: build passes, clean working tree, `pnpm e2e` passes on the release commit (local wp-env only; CI doesn't run it), the free zip loads the admin page on a clean site.

## Staging

`https://staging-yay.ninjateam.org`, shared by the Yay plugins. Runs Pro: each plugin's latest release, plus the rc about to ship.

- Access: your own SSH key, one per device; send devops the `.pub` only. Host `yay-staging` in `~/.ssh/config`, then `STAGING_SSH` and `STAGING_URL` in `dev/.env.local`. Check: `wp --ssh=<STAGING_SSH> option get home`.
- Deploy: commit, then `./run.sh deploy`. Fallback: `./run.sh release pro --rc` and upload in wp-admin.
- Mail never leaves: the must-use `dev/wp/mu-plugins/yay-mail-log.php` stores it under Tools › Mail log, and deploy refuses without it. A new site gets it first, then WooCommerce, deploy, `./run.sh seed --staging`.
- No system cron, on purpose: workflows run on page visits, as on a client site without one. Load a page to push a waiting run.
- Testers: fake data only (`.test` emails); deactivate your workflows when done, since active ones fire on everyone's orders; check the site after each deploy.
- Recovery: `./run.sh reset-data --staging --yes`, then the seed.
- Web PHP is 8.5, wp-cli PHP 8.1.
