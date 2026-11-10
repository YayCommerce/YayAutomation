# Plan: YayMatik 1.0 staging deploy and e2e

Release target: about 2026-11-12. Status: confirmed 2026-11-05.

## Goal
One shared Yay staging site, a small deploy script and a 4–6 journey Playwright suite on wp-env, without Bookster-sized tooling.

## Approach
- **Zip.** `./run.sh release pro --rc` stamps `X.Y.Z-rcN` (N = `git rev-list --count HEAD`) on the header and `YAYMATIK_VERSION` in the build copy, next to the dev-code removal in `dev/scripts/release.sh`. It aborts if either line didn't change. In `release.sh`, not `deploy.sh`, so the manual wp-admin fallback gets the same zip. The file name stays `release/yaymatik-pro.zip`.
- **Deploy.** `dev/scripts/deploy.sh`, about 30 lines. Refuse uncommitted changes, check `wp option get home` against `STAGING_URL`, build, `scp`, `wp plugin install --force --activate`, print the version and short SHA, remove the temp zip. Settings: `STAGING_SSH`, `STAGING_URL` in `dev/.env.local`. In a worktree, pass them in the shell.
- **Mail.** `dev/wp/mu-plugins/yay-mail-log.php`, one file used by wp-env and staging. `pre_wp_mail` returns true, so `SendEmail` and `SendInternalEmail` see success, and nothing leaves. A private post type stores to / subject / body and keeps the last 500. Save with kses off: cron and customer requests would otherwise strip the email's HTML. A Tools page shows the list, with the body in a sandboxed iframe.
- **wp-env.** Root `.wp-env.json` (also in `.distignore`): port 8910, no tests environment, WooCommerce pinned to one version, the checkout as a plugin, `wp-content/mu-plugins` mapped to `dev/wp/mu-plugins`. It runs the checkout, so it needs `./run.sh setup` and `pnpm build` first. `includes/Pro` is present, so it runs as Pro; Pro's modules are empty subclasses today, so free and Pro behave the same.
- **Seed.** `dev/scripts/seed.php` + `seed.sh`, like the `check-*` pairs. Additive and idempotent (by SKU and email): cash on delivery on, products, one `.test` customer, cart tracking on. The target comes from the caller: local `wp`, `wp-env run cli wp`, or `wp --ssh=$STAGING_SSH`. Orders it creates fire any active workflow on staging; that mail only reaches the log.
- **E2E.** `dev/e2e/` holds specs, config and output, so nothing ships. Root dev dependencies `@playwright/test`, `@wordpress/env`; root script `pnpm e2e`. One worker: active workflows fire on every order, so a suite-level `beforeEach` deactivates all workflows. A helper runs `wp action-scheduler run`, because wp-env's loopback can't reach the mapped port. Selectors by role and text; the app has no e2e test ids.
- **Time travel, for abandoned cart.** `abandon_minutes` has a 15-minute floor (`Settings.php:15`) and the template waits 1 hour first. The helper backdates `last_activity_at`, fires `yaymatik_cart_sweep`, and moves the pending wait job to now. No long wait, no plugin change.
- **Bug-fix line** (dev convention): worktree, then `./run.sh setup && pnpm install && pnpm build && WP_ENV_PORT=8920 pnpm wp-env start`, then `./run.sh seed --wp-env`. wp-env 12 deprecates the tests environment, so `.wp-env.json` turns it off and one port is enough.

## Checkpoints

1. **Devops requests** — Risk: 1 — Send both texts below, today. Nothing else waits on them until checkpoints 5 and 6.
2. **Stamped zip and deploy script** — Risk: 2 — `--rc` in `release.sh`, `deploy.sh`, `.env.example`, `dev/README.md`. Test: unzip shows the same rc in header and constant; a plain `./run.sh release` zip has no stamp; a dirty tree and a wrong URL are both refused. The real install waits for checkpoint 6.
3. **wp-env, mail log, seed** — Risk: 2 — `.wp-env.json`, `.distignore`, mu-plugin, seed. Test on wp-env: seed twice gives no duplicates; a WooCommerce order and a `SendEmail` step both appear in the log; the Tools page renders the HTML; `wp_mail` returns true with no send attempt.
4. **Playwright journeys** — Risk: 2 — All green locally, three runs in a row.
   - Build from a template: library, preview, use, editor opens with no issues.
   - Activate: list shows Active.
   - Order: block checkout with cash on delivery, run the queue, run shows on the overview, email in the log.
   - Deactivated: same order, no new run.
   - Abandoned checkout: guest reaches checkout, email captured, time travel, email in the log, and its link restores the cart. Checkout template, not Cart reminder: a guest only gives an email at checkout, and that makes the abandon `checkout_abandoned`.
5. ~~CI job~~ — Dropped (decision 8): e2e runs locally, before a release only. Devops request B isn't needed.
6. **First staging run** — Risk: 3 — Blocked on SSH. Order matters: mail mu-plugin first, then WooCommerce and seed, then the first deploy. Test: `wp_mail` from wp-cli lands in the log and not an inbox; the rc shows in wp-admin; `main.js?ver=` carries the rc; a test order runs through real cron. **Fallback:** build with `--rc`, upload in wp-admin.
   - Done 2026-11-09: `1.0.0-rc271` deployed by script; mail log catches `wp_mail`; seed runs after the deploy (it now refuses before YayMatik is active). No system cron, on purpose: staging tests a client site without one. Web PHP 8.5, wp-cli PHP 8.1. WooCommerce 11.2.2. admin-shell v2.10.0 admin page loads.
7. **cw fixes and docs** — Risk: 1 — `claude-workflow.sh`: target windows by index (`win()`, including `attach`, `start_server`, `cleanup`) and restart a window whose Claude exited (`open_window`, `start_server`). Docs below. Test: two windows with one name, and a window whose Claude exited.

**By hand before release, not a checkpoint:** install the free zip on a clean site and load the admin page.

## Docs (checkpoint 7)
- Settled staging decisions: `.claude/docs/ops-release.md` (decision 9). `docs/plan-infra.md` is deleted.
- `.claude/docs/ops-release.md`: deploy, `--rc`, staging rules for testers (fake data only; deactivate your workflows when done; check after each deploy).
- `.claude/docs/context-dev-convention.md`: the bug-fix line, `pnpm e2e`.
- `docs/ROADMAP-dev.md` M9.1, `docs/ROADMAP-v1.0.md` release criteria: per decision 1.
- `docs/ROADMAP-v1.0.md:295` still says version 0.1.0. `docs/ROADMAP-dev.md:265` says to delete `reset-data.sh`, which e2e and staging recovery now depend on.

## Devops requests (paste-ready)

**A. Staging site**
> Please set up a new WordPress site at `staging-yay.ninjateam.org` on the Bookster staging host, separate from `staging-bookster.ninjateam.org`. Same setup as the Bookster guide: https://claude.ai/artifact/UvedHGHB1JTzdc1BEDhQCB
> - SSH to the origin host (not through Cloudflare) with wp-cli on the server. Public keys per dev to follow.
> - WP 6.5+, PHP 7.4+, WooCommerce-ready.
> - A real system cron calling WP-Cron every minute. Our workflows run on Action Scheduler and must not depend on page traffic or a loopback through Cloudflare.
> - No page cache on wp-admin, `/wp-json`, cart or checkout. No indexing.
> Please send: SSH user, host, port and the WordPress path.

**B. CI deploy key**
> Please add a **read-only** deploy key to `YayCommerce/yaycommerce-plugin-admin-shell`, and put its private half in `YayCommerce/YayAutomation` Actions secrets as `ADMIN_SHELL_DEPLOY_KEY`. CI needs it to run `composer install`. The private key goes straight into the secret, not through chat.

## Findings against the brief

**Contradictions**
- `docs/ROADMAP-v1.0.md:274` makes "every one of the 18 templates … the email arrives in the inbox" a release criterion, and `ROADMAP-dev.md:259` gives M9.1 the same scope plus classic checkout. The brief has 4–6 journeys, and staging stops mail. Open 1.
- `?ver=` only changes when N does. `git commit --amend` or a sibling branch gives the same N, so browsers keep the old `main.js`. Open 4.
- "Recovery is re-seed" can't repair YayMatik's tables, and `reset-data.sh` only targets a local site: its `--skip-plugins` uses the checkout's folder name, and staging's is `yaymatik`. Open 5.
- `yaymatik.php` is still `0.1.0`, so the first stamp reads `0.1.0-rcN`. Open 6.
- Composer's admin-shell URL is HTTPS and a deploy key is SSH. Composer should fall back to an SSH clone when non-interactive. Not proven; check it in checkpoint 5. If it fails, point the repo at `git@github.com:` in CI only.
- `composer.lock` is gitignored and `yaycommerce-prerelease` runs `composer update`, so a zip isn't pinned to its commit. A fresh resolve today gives admin-shell **v2.10.0**; this checkout has **v2.8.6**. So the first rc is also the first test of that jump. Staging does test what ships, so no change, but check it in checkpoint 6.
- This checkout's lock is stale: it has php-scoper 0.18.18 and `composer.json` needs `^0.18.20`, so `composer install` from it fails. A `composer update` here fixes it.

**Risks, no change**
- Prerelease rewrites tracked `composer.json` and `scoper.inc.php`. Run in a scratch copy today (13s): both stay clean. A new php-scoper or safe release would dirty `composer.json`, and the next deploy would refuse until that is committed.
- `release.sh` uses BSD `sed -i ""`, so it only works on macOS. Deploying from Linux would fail. CI doesn't call it.
- The brief says "pause"; the app says Deactivate, which also stops waiting runs (`ROADMAP-v1.0.md`, criterion 4). Tester docs use Deactivate.
- `ROADMAP-v1.0.md:263` still says full scope doesn't fit by Nov 30, with no release date.
- `ROADMAP-dev.md:138` assigns an editor server-issues test to "the e2e plan". Not one of the journeys; keep it on the backlog unless open 1 changes that.

**Confirmed as the brief says**
- `RegisterProd.php:15` and the fixed `main.js` name; style and `capture-email.js` use the same constant; chunks are hashed.
- Mail: `WC()->mailer()` with no environment guard (`SendEmail.php:129`, `SendInternalEmail.php:89`, `EmailPreview.php:76`).
- `ci.yml` has no main `composer install`, no `workflow_dispatch`.
- No `register_activation_hook`, so Bookster's `wp plugin activate` bug doesn't apply.

## Decisions

Settled by the brief:
- Sites, deploy steps, rc stamp, fake data with logged mail, Playwright on wp-env only, one CI job, bug-fix flow, `claude-workflow.sh` limited to two fixes, free zip by hand.

Confirmed:
1. Release criteria: keep them, but test each of the 8 workflows once rather than all 18 templates, by hand on staging from the mail log, plus one real inbox check on a dev site. Journeys are the smoke test. `docs/ROADMAP-v1.0.md` and `docs/ROADMAP-dev.md` M9.1 change to match in checkpoint 7.
2. Staging subdomain: `staging-yay.ninjateam.org`.
3. Deploy refuses if the mail log is off or another plugin has replaced `wp_mail`.
4. Deploy refuses if the site already has this exact rc.
5. `reset-data.sh` can target staging. It wipes YayMatik's data only.
6. Version goes to `1.0.0` on this branch, in checkpoint 2.
7. Review effort: one `medium` review of the whole branch before the PR, told that the rc number and floating admin-shell are decided.
8. No CI e2e job: `pnpm e2e` runs locally, before a release only.
9. Staging and release notes live in `.claude/docs/ops-release.md`; `docs/plan-infra.md` is deleted.
10. Staging has no system cron, to test a client site without one.

## Out of Scope
Monorepo, shared dev-kit repo, `addons/` folder, sibling zips in CI, nightly e2e, shared-safe suites, release boards, screenshot tool, screenshots and doc draft, Bookster's `-fixN`, DB export, `--restore`, `--dry-run`, `docker:` target, e2e on staging, site script, permanent test sites, the `RUN_CWD` fix for "Session here".

## Meta
- Target branch: feature/staging-e2e
- Base branch: develop
