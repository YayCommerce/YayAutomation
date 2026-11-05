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
| `./run.sh seed [--wp-env\|--staging]` | Add the fake store data: products, a customer, cash on delivery, cart tracking. Safe to run again. |
| `./run.sh reset-data [--wp-env\|--staging] [--yes]` | Delete all YayMatik data on that site. Staging recovery: this, then `seed`. |
| `./run.sh deploy` | Build a Pro rc zip of this commit and install it on staging. Refuses uncommitted changes, a wrong site, a missing mail log and an rc the site already has. |
| `./run.sh claude-workflow` (alias `cw`) | Claude work menu: tmux + Remote Control sessions. macOS, needs `zsh`, `tmux`, `claude`. |

Add a command: drop an executable `dev/scripts/<name>.sh`. Line 2 comment is its description.

## Personal settings

First `./run.sh` creates `dev/.env.local` from `dev/.env.example`. Edit it once. It is gitignored.

| Variable | Default | Use |
| --- | --- | --- |
| `CW_MODE` | `default` | Permission mode for `claude-workflow` sessions. Set `auto` to skip approvals. |
| `CW_DEVICE` | `work` | Device name in the Claude app. |
| `STAGING_SSH` | | `deploy` target: `user@host[:port]/wp/path`. |
| `STAGING_URL` | | Staging home URL, checked before anything is written. |

One-off override: `CW_MODE=acceptEdits ./run.sh cw`.
