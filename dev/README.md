# dev/

Team-only files. Not shipped in the release zip (see `.distignore`).

## Commands

Run everything through `./run.sh` in the plugin root. It works from any folder.

| Command | What it does |
| --- | --- |
| `./run.sh` | List commands |
| `./run.sh setup` | `composer install` + scoped admin-shell → `vendor/autoload.php`, `vendor-prefixed/`, plus the PHP lint and test tools in `dev/tools/lintphp` and `dev/tools/phpunit`. `pnpm dev` runs it if `vendor/autoload.php` is missing. |
| `./run.sh meta-fixture` | Write `apps/admin-page/src/test/fixtures/meta.json` (PHP's `window.yayMatikMeta`) from the local site with wp-cli. Run after changing what PHP sends. |
| `./run.sh release [pro] [--rc]` | Build `release/yaymatik.zip`, or `yaymatik-pro.zip` with `pro`. `--rc` stamps `X.Y.Z-rcN` (N = commit count) in the zip copy. |
| `./run.sh seed [--wp-env\|--wp-env-min\|--staging]` | Add the fake store data: products, a customer, cash on delivery, cart tracking, a "Classic checkout" page (`/classic-checkout/`). Safe to run again. |
| `./run.sh reset-data [--wp-env\|--wp-env-min\|--staging] [--yes]` | Delete all YayMatik data on that site. Staging recovery: this, then `seed`. |
| `./run.sh check-all [--wp-env\|--wp-env-min]` | Run every PASS/FAIL check on one site and print one table; exits 1 on any FAIL. Each `check-*` also runs on its own with the same targets. |
| `./run.sh bench-privacy --wp-env\|--wp-env-min [sizes…]` | Seed 50k and 200k runs (or the sizes given), then print EXPLAIN and median ms of each privacy lookup, old full scan vs the index, and the write cost of indexing. Removes its rows. |
| `./run.sh probe-mysql --staging` | Read-only probe of staging's MySQL 8 (SELECTs only): the privacy index SQL, `GET_LOCK`, the user meta key collation. |
| `./run.sh deploy` | Build a Pro rc zip of this commit and install it on staging. Refuses uncommitted changes, a wrong site, a missing mail log and an rc the site already has. |
| `./run.sh claude-workflow` (alias `cw`) | Claude work menu: tmux + Remote Control sessions. macOS, needs `zsh`, `tmux`, `claude`. |

Add a command: drop an executable `dev/scripts/<name>.sh`. Line 2 comment is its description.

## Test sites

Scripts with a target take one of these. `check-*` scripts write test rows, so they refuse `--staging`.

| Target | Site |
| --- | --- |
| (none) | The local site, through your `wp` (`WP_PATH` if set). |
| `--wp-env` | `pnpm wp-env start`: port 8910, latest WordPress, PHP 8.1, WooCommerce 11.3.0. `pnpm e2e` runs here. |
| `--wp-env-min` | `cd dev/wp-env-min && ../../node_modules/.bin/wp-env start`: port 8911, WordPress 6.9, PHP 7.4, WooCommerce 11.0.0, the lowest versions YayMatik supports. |
| `--staging` | Staging (`STAGING_SSH`, `STAGING_URL`): seed, reset-data, probe-mysql only. |

## Personal settings

First `./run.sh` creates `dev/.env.local` from `dev/.env.example`. Edit it once. It is gitignored.

| Variable | Default | Use |
| --- | --- | --- |
| `CW_MODE` | `default` | Permission mode for `claude-workflow` sessions. Set `auto` to skip approvals. |
| `CW_DEVICE` | `work` | Device name in the Claude app. |
| `STAGING_SSH` | | `deploy` target: `user@host[:port]/wp/path`. |
| `STAGING_URL` | | Staging home URL, checked before anything is written. |

One-off override: `CW_MODE=acceptEdits ./run.sh cw`.
