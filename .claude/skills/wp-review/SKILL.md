---
description: WordPress.org pre-submission review — build the free zip, run Plugin Check on it on wp-env, and check it against the release checklist. Reports only; does not fix.
---

# /wp-review

Review the **free release zip** against `.claude/docs/context-wporg-release-checklist.md`. Report only. Do not change code.

## Steps

1. **Read the checklist** in full. It is the source of truth.
2. **Build and extract** into the session scratchpad:
   ```bash
   ./run.sh release > <scratchpad>/release.log 2>&1; echo "exit: $?"
   rm -rf <scratchpad>/free && mkdir -p <scratchpad>/free && unzip -q release/yaymatik.zip -d <scratchpad>/free
   ```
   If the build fails, stop and show the end of the log.
3. **Run each item's How** in `<scratchpad>/free/yaymatik`, not the dev folder. Read the code behind every grep hit before you call it a finding.
4. **Run Plugin Check (T1)** on the zip, on the wp-env site (`pnpm wp-env start` first if it is down). Its plugins folder maps this checkout as `YayAutomation`, so the zip installs beside it as `yaymatik`.
   1. `pnpm wp-env run cli wp plugin is-installed plugin-check; echo $?`. If not (`1`), ask once, then `pnpm wp-env run cli wp plugin install plugin-check --activate`.
   2. If `pnpm wp-env run cli wp plugin is-installed yaymatik` returns `0`, stop and ask.
   3. Install the zip, check it, then delete it:
      ```bash
      pnpm wp-env run cli wp plugin install wp-content/plugins/YayAutomation/release/yaymatik.zip
      pnpm wp-env run cli wp plugin check yaymatik --format=json --include-experimental 2>/dev/null | grep -v '^Deprecated:' > <scratchpad>/plugin-check.json
      pnpm wp-env run cli wp plugin delete yaymatik
      ```
      Do not activate it: the mapped checkout defines the same classes.
   4. Group the results by `code`. ERRORs are Must fix. WARNINGs are Should fix. Read the code behind each one, like a grep hit.
5. **Report.** Start with **Ready** or **Not ready (N blockers)**, then:
   - **Must fix:** ID, file:line, problem, fix.
   - **Should fix:** ID and why.
   - **Passed:** IDs, one line per section.
   - **Not checked:** IDs and why.

   Then ask which items to fix.

## Project specifics

- **G1:** the zip has no `includes/Pro/` and no `vendor-prefixed/src/License/` (no updater, no license client). `YayMatikMenuAdapter` is the free menu. admin-shell's license views and `license.js` still ship, as in YayBulkOrder's approved zip; every License-namespace use left in `vendor-prefixed` sits behind a `class_exists` guard (`dev/scripts/lib/free-admin-shell.php`).
- **B1:** every GitHub link in `readme.txt` must return HTTP 200 (`curl -s -o /dev/null -w '%{http_code}' <url>`). A 404 means `YayCommerce/YayAutomation` is still private: a blocker at submission. Once public, check the reviewed commit is on `develop` (`git merge-base --is-ancestor <commit> origin/develop`) and report it if not.
- **Q2:** PHP names (options, meta, transients, tables, hooks, constants) use `yaymatik` / `YAYMATIK_` / `YayMatik\`. `yay-*` CSS classes and `yayMatikMeta` are the admin app's.
- **Q7, Q10:** `./run.sh check-plugin-lifecycle --wp-env` (and `--wp-env-min`) covers deactivate, uninstall and the forced old WordPress; report its result.
- **Q11:** needs a clean `--wp-env-min` site (`wp-env clean all`). Without it, report "not checked".
- **T2:** `pnpm lint:php`.
- **T3:** `./run.sh check-all --wp-env` and `--wp-env-min`.

## Gotchas

- WP-CLI prints `Deprecated:` warnings to stdout. Add `2>/dev/null`, and never capture its output into a path variable.
- Findings in `vendor-prefixed/` belong to the shared `yaycommerce/admin-shell` package (its text domain is `yaycommerce`). Say the fix goes there.
- `release/` may hold old extracted folders. Check only the fresh zip.
