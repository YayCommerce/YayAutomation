# dev/

Team-only files. Not shipped in the release zip (see `.distignore`).

## Commands

Run everything through `./run.sh` in the plugin root. It works from any folder.

| Command | What it does |
| --- | --- |
| `./run.sh` | List commands |
| `./run.sh setup` | `composer install` + scoped admin-shell → `vendor/autoload.php`, `vendor-prefixed/`, plus the PHP lint tools in `dev/tools/lintphp`. `pnpm dev` runs it if `vendor/autoload.php` is missing. |
| `./run.sh meta-fixture` | Write `apps/admin-page/src/test/fixtures/meta.json` (PHP's `window.yayAutomationMeta`) from the local site with wp-cli. Run after changing what PHP sends. |
| `./run.sh release` | Build `release/yay-automation.zip` |
| `./run.sh claude-workflow` (alias `cw`) | Claude work menu: tmux + Remote Control sessions. macOS, needs `zsh`, `tmux`, `claude`. |

Add a command: drop an executable `dev/scripts/<name>.sh`. Line 2 comment is its description.

## Personal settings

First `./run.sh` creates `dev/.env.local` from `dev/.env.example`. Edit it once. It is gitignored.

| Variable | Default | Use |
| --- | --- | --- |
| `CW_MODE` | `default` | Permission mode for `claude-workflow` sessions. Set `auto` to skip approvals. |
| `CW_DEVICE` | `work` | Device name in the Claude app. |

One-off override: `CW_MODE=acceptEdits ./run.sh cw`.
