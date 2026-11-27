---
description: WordPress.org release of the free YayMatik zip, end to end — slug and header preflight, build, automated wp.org gates (check-wporg.sh), Plugin Check, checklist review, clean-site smoke, and the reply to the review email. Stops at the first failure. Never uploads or emails.
---

# /wp-release

Takes the free edition from a clean `develop` (or a fix branch) to a zip that is ready to upload to WordPress.org, plus the reply text. Source of truth: `.claude/docs/context-wporg-release-checklist.md`, including its **Review history** section. Read both in full first.

Inputs: optional `round=<n>` and the review email saved as `.claude/plans/dev/<plan>-review-email.md` when answering a review.

## Rules learned from review round 1 (Nov 2026). Never break these.

1. **No edition-dependent limits in the free code.** A setting, range, count, duration or feature that the free code can run must be fully usable in the free zip. A Pro build must not widen a table that the free code holds (`Settings::allow()` pattern). If Pro wants more, Pro ships the code. (G4, G8)
2. **No license, updater, trial or installer surface in the free zip.** No license pages, views, JS or CSS, no license registry or bridges, no "Go Pro" links. The shared admin-shell ships stripped (`dev/scripts/lib/free-admin-shell.php`), registers for the shell election and the AJAX guard as `0.0.1` (not `0.0.0`: a lone copy must still win) so a full copy from another Yay plugin always wins, and its Recommended Plugins installer only installs slugs from its own wordpress.org list and only answers its own page (`shell=wporg`). (G1, G9)
3. **The free code never says "license", "trial", "unlock", "premium" or "upgrade to".** Saying that a step comes from YayMatik Pro is fine. (G4)
4. **A nonce wherever a browser session exists.** Token-only handlers are allowed only where no session can exist (email links, RFC 8058 one-click). Each one is listed in `dev/wporg/nonce-exceptions.txt` with its reason. A `phpcs:disable NonceVerification` is not a reason. (S1, S14)
5. **Text domain = slug = zip folder.** For YayMatik Lite that is `yaymatik-automation-for-woocommerce` (leader's decision, Nov 26). For a new plugin, the slug comes from the Plugin Name unless one is asked for at submission, so decide it before the first upload. No string in another domain (vendored code included). Code prefixes (`yaymatik_`) don't need to match the slug. (Q5, U5)
6. **Every URL in the header and the readme returns 2xx without credentials**, the source repo included, on the day of upload. (R7, B1)
7. **A reported issue is a class, not an instance.** Fix every case in the zip, including `vendor-prefixed/` if it ships. The reviewer rejects a second round with the same kind of issue. (U4)

## Steps

1. **Preflight, before building.**
   - `git status` clean; branch and HEAD printed.
   - Versions: `Version`, `YAYMATIK_VERSION`, `Stable tag` equal (x.y.z). `Tested up to` / `WC tested up to` are the current majors (search the web for the latest WordPress and WooCommerce releases).
   - Slug: read `WPORG_SLUG` from `dev/scripts/lib/wporg.sh` (`yaymatik-automation-for-woocommerce`). It must equal the header `Text Domain`, the zip's top folder, the `.pot` name and the `wp_set_script_translations` domain. If the Plugin Name changed, write down the slug WordPress.org would produce from it (lowercase, words joined by `-`, punctuation dropped) and stop to ask.
   - If answering a review: build a table with one row per finding (reviewer text → file:line in the current tree → class of issue → planned fix). Search the whole tree for each class.
2. **Build.** `./run.sh release > <scratchpad>/release.log 2>&1; echo "exit: $?"`. It runs `check-lite-zip.sh` and `check-wporg.sh`. On failure show the end of the log and stop.
3. **Automated gates on the zip.** `./run.sh check-wporg release/yaymatik-automation-for-woocommerce.zip --urls`. Every gate must pass: text domain, words, edition limits, request handlers, installer/license surface, URLs, external services. Don't edit an allow-list to get a pass without saying so in the report, with the reason.
4. **Plugin Check** on wp-env, as in `/wp-review` step 5, with `--include-experimental`. Target: 0 errors, 0 warnings. Read each warning's code before calling it a false positive.
5. **Checklist.** Run `/wp-review` (report only) on the same zip. Every item must be Passed or have a written reason.
6. **Clean-site smoke**, with `WP_DEBUG` and `WP_DEBUG_LOG` on: fresh wp-env site, latest WordPress + WooCommerce, then `--wp-env-min` (PHP 7.4, WP 6.9). Install the zip through upload, activate, open every YayMatik screen, save each setting at its max, run one workflow, deactivate, delete. Then repeat with another YayCommerce free plugin active. `debug.log` must stay empty of YayMatik lines.
7. **Report.** Start with **Ready to upload** or **Not ready (N)**, then: HEAD, zip sha256, test numbers, Plugin Check result, each gate's result, the findings table (if a review), and anything allow-listed in this run.
8. **Reply text** (if a review): at most 6 lines, in the same thread. No change list. Include only: a permalink request (only if the slug must change before approval), context the reviewer can't see in code (token-only handlers and why), the public source link, and questions on findings you believe are false positives, each with one example. Save it as `.claude/plans/dev/<plan>-reply.md`.
9. **After the round.** Append the round to the checklist's **Review history**: date, review id, each finding, the root cause in code and in process, and the new gate or item that now catches it. If a finding was not caught by any gate, add a gate in `check-wporg.sh` before closing the plan.

## Never

- Upload the zip, email the reviewer, push, or change the permalink. Jack does that.
- Ship `includes/Pro/`, `src/License`, admin-shell license files, or a Pro-only setting range in the free zip.
- Put a limit back into the free code after approval. Guideline 5 applies to every hosted version.
- Answer a finding with "it's only in vendored code".

## Gotchas

- WP-CLI prints `Deprecated:` lines to stdout: add `2>/dev/null` and never capture its output into a path.
- `wp i18n make-pot --ignore-domain` counts every gettext call. The difference from `--domain=<slug>` is the number of strings in other domains.
- GitHub may rate-limit unauthenticated requests: when gate 6 fails on a github.com URL, wait 30 s and run `check-wporg --urls` once more before calling it a failure.
- `release/` keeps old zips and folders. Check only the zip from this run.
