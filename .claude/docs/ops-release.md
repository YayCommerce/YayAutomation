# Ops: Release

Use `/wdist`.

- Zip: `./run.sh release` (`dev/scripts/release.sh`) → `release/yaymatik.zip` (free: `.distignore` leaves out `includes/Pro/`; `release.sh` removes admin-shell's `src/License`, as YayBulkOrder's lite build does, and `dev/scripts/lib/free-admin-shell.php` adds `class_exists` guards and ABSPATH guards to the rest of the scoped copy, failing the build when admin-shell changes under it). `composer.json` ships beside `vendor/`. `./run.sh release pro` → `release/yaymatik-pro.zip`, the same with `includes/Pro/`. Exclusions: `.distignore`. It runs `./vendor/bin/yaycommerce-prerelease` first and ships only `vendor/autoload.php` + `vendor/composer/` + `vendor-prefixed/`.
- WordPress.org review: `/wp-review` checks the free zip against `.claude/docs/context-wporg-release-checklist.md` (IDs S, G, B, Q, R, T, U).
- Versions: `Version:` and `YAYMATIK_VERSION` in `yaymatik.php` and `Stable tag:` in `readme.txt` are equal.

## Release gate

On the release commit, clean working tree, in this order; stop at the first failure:

1. `pnpm lint`, `pnpm lint:php`, `pnpm test`, `pnpm test:php`, `pnpm build`.
2. `./run.sh check-all --wp-env` and `./run.sh check-all --wp-env-min`; `./run.sh probe-mysql --staging` (MySQL 8).
3. `pnpm e2e` (wp-env only; CI doesn't run it).
4. `/wp-review` on the free zip.
5. By hand: each of the 8 workflows once on staging, one email in a real inbox from a dev site, the free zip uploaded and smoke-tested on a clean site.

## Staging

`https://staging-yay.ninjateam.org`, shared by the Yay plugins. Runs Pro: each plugin's latest release, plus the rc about to ship.

- Access: your own SSH key, one per device; send devops the `.pub` only. Host `yay-staging` in `~/.ssh/config`, then `STAGING_SSH` and `STAGING_URL` in `dev/.env.local`. Check: `wp --ssh=<STAGING_SSH> option get home`.
- Deploy: commit, then `./run.sh deploy`. Fallback: `./run.sh release pro --rc` and upload in wp-admin.
- Mail never leaves: the must-use `dev/wp/mu-plugins/yay-mail-log.php` stores it under Tools › Mail log, and deploy refuses without it. A new site gets it first, then WooCommerce, deploy, `./run.sh seed --staging`.
- No system cron, on purpose: workflows run on page visits, as on a client site without one. Load a page to push a waiting run.
- Testers: fake data only (`.test` emails); deactivate your workflows when done, since active ones fire on everyone's orders; check the site after each deploy.
- Recovery: `./run.sh reset-data --staging --yes`, then the seed.
- Web PHP is 8.5, wp-cli PHP 8.1.
