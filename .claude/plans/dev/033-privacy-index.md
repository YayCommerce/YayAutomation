# Plan: Privacy lookup index + cart skip visibility

Status: written 2026-11-12, reviewed by Jack the same day (O1–O3 approved as recommended, 5 amendments folded in). One branch `jack/feature/033-privacy-index` off develop (9b31ca7), one PR, one-pass mode. Must ship before the WordPress.org submission. The Extension API moves to 034.

## Goal
A privacy export or erase page finds a person's runs through an index instead of scanning every run and step, and a cart abandonment that no workflow takes stays free for a later one. A cart claim a workflow rejects shows as a Skipped run with its reason.

## Approach

### Part 1: privacy index
- **Table** `run_emails` (D1): `run_id bigint(20) unsigned`, `email varchar(190)`, `UNIQUE KEY run_email (run_id,email)`, `KEY email (email,run_id)`. `runs` gets `KEY subject (subject,status)`. `DB_VERSION` 7 → 8; dbDelta adds both. `Schema::RUN_EMAILS`, the install check list, `Uninstaller::TABLES` and the `reset-data.sh` list get it.
- **Extractor**, a private part of `RunStore`. It returns the lowercased values the old scan would match, truncated to 190 chars:
  - JSON columns (`scope`, step `input`, `output`): decode, then walk every string, keys included. A string counts when it is a whole normalized email (`strtolower( $s ) === UnsubscribeLink::normalize( $s )`) or a claim key (`email:` + 64 hex). This mirrors `json_like()`: the old LIKE only matches the email as a whole JSON string, in a `_ci` column.
  - Text columns (`runs.error`, step `error`): for each `@`, every start the lookbehind of `replace_text()` allows and every end its lookahead allows. Each candidate is kept when `is_email()` passes. That is the exact set of normalized emails `text_regexp()` would match, apostrophes and trailing dots included.
- **Writer** `RunStore::index_emails( int $run_id, array $json, array $text )`, used by both stores (D2). It does nothing when no email is found, so most writes cost no extra query. Otherwise it sends one `INSERT IGNORE … VALUES (…),(…)`. Everything sits in `try`/`catch ( \Throwable )`. A `false` result or a throw goes to `Logger::error` and the write that called it still returns its own result (D8).
- **Call sites**, all seven:
  - `RunStore::create` (scope; error when given, see E2) and `RunStore::save` (scope and/or error when in `$fields`).
  - `RunStore::cancel_waiting`: the reason, only for the runs the UPDATE changed. When the reason holds an email, `INSERT IGNORE … SELECT id, %s FROM runs WHERE id IN (…) AND status = 'cancelled' AND error = %s`, so a run its job took meanwhile isn't indexed.
  - `RunStepStore::start` (input), `finish` (output, error), `close_open` (error, only when the UPDATE changed a row), `cancel_waiting` (reason, same SELECT form over the runs the UPDATE matched).
- **`about()`** (D3) becomes a derived id list:
  - `SELECT run_id AS id FROM run_emails WHERE email IN (%s, %s)` (the normalized email and its claim key, both truncated the same way)
  - `UNION SELECT id FROM runs WHERE subject IN (…) [AND status = 'waiting']`
  - Callers join it as `INNER JOIN (…) m ON m.id = r.id`, keep their own status filters, and `ORDER BY r.id LIMIT`. `for_export` keeps `OFFSET`. Signatures and `PersonalData.php` stay as they are.
- **Sync** (D4):
  - `anonymize()` deletes the person's rows (email + claim key) for the runs it was given, after the scrub.
  - `delete_with_steps()` deletes `run_emails` rows first, then steps, then runs, so a failure leaves no index row without its run.
  - Workflow delete keeps runs, so nothing changes there.
- **No backfill** (D5). The PR text says: run `./run.sh reset-data` on staging and tester sites after the deploy.
- **Proof** (D6). The old OR-chain SQL lives in `dev/scripts/lib/privacy-old-scan.php`, required by both scripts only.
  - `bench-privacy` runs on wp-env only. It seeds 50k and 200k runs (3–6 steps each, realistic JSON: refs, email step input and output, some errors with emails) through bulk SQL plus the indexer. It prints EXPLAIN and the median ms of `for_export` p1/p2, `ids_mentioning`, `in_progress_mentioning` and `waiting_ids_for_subjects`, old vs new. It also prints write cost: create + 5 steps with and without indexing. It deletes its rows at the end (marker workflow id). Pass: no `ALL` on runs or run_steps in EXPLAIN and < 50 ms per page at 200k.
  - `check-privacy-index` runs on any wp-env target and is added to check-all. It seeds runs through the real stores, covering edge texts (case, a trailing dot, `.au`, hyphen, apostrophe, an email as a JSON key, inside a longer string, a claim key), plus a Skipped cart run (E2) made through the Dispatcher. For every seeded email the new id lists must equal the old scan for all three lookups, before and after an erase, and after a cleanup delete. Export must list the Skipped run, and erase must change it and leave it findable no more.

### Part 2: cart skips
- **E1: lock a cart only when a series starts.**
  - `CartSweep::run` marks every due cart abandoned, as today. It fires `do_action( 'yaymatik_cart_abandoned', $id, $kind )` whenever the row has no `series_at`. The kind comes from `kind()` at this abandonment (I3).
  - `CartStore::mark_abandoned` loses `$kind` and sets status only. `due()` also returns `series_at`.
  - `CartTrigger::listen` emits when `$kind === $this->id()`. The Dispatcher reaches `claim()` only when a workflow for that trigger is active, so an abandonment nobody takes writes nothing (I4).
  - `claim()` ends with `CartStore::start_series( $id, $kind )`: `UPDATE … SET series_at = now, abandoned_at = now, abandon_trigger = %s WHERE id = %d AND series_at IS NULL AND status <> 'ordered'`, true on 1 row. That is the at-most-one-series lock (I1), and it is atomic, so Cart and Checkout can never both start. `mark_series` goes.
  - `series_taken` (I2) and `reset_cycle` stay. `abandoned_at` / `abandon_trigger` now mean "the abandonment that started the series"; the carts comment in `Schema.php` (:153) says so.
  - Hook change: `yaymatik_cart_abandoned` now fires on every abandonment of a cart without a series, and gains `$kind` (`cart_abandoned` / `checkout_abandoned`). It is experimental like the other hooks (032 D18). Noted in `docs/workflow-core-decisions.md` and in the hooks line of `docs/workflow-core-design.md` §8.
- **E2: Skipped runs.**
  - `Trigger::skip_reason( array $args ): ?string`, default null: why `claim()` would refuse. `CartTrigger::claim()` = `null === $this->skip_reason( $args ) && CartStore::start_series(…)`.
  - Reasons: cart ordered, shopper unsubscribed, "This shopper already got a reminder series in the last 7 days." A missing cart, or losing the `start_series` race, gives null: no record.
  - `Dispatcher::dispatch`: when `claim()` is false and `skip_reason()` gives a string, each active workflow whose trigger node is this trigger and whose config validates gets one run. `create_run` is called with `status` skipped and `error` = the reason, with the same refs scope as a real run, not queued.
  - `RunStore::create` accepts `status` (queued or skipped) and `error`, and sets `finished_at` for skipped. One INSERT, so no queued row can be picked up in between.
  - The existing Skipped tab shows them. No UI change, unless the `CartTriggerNotes` hints turn out wrong under E1.
  - Side effects, accepted and tested:
    - Skipped runs count in the overview's Runs (30 days) and Last run (`RunStore::stats()` counts every status).
    - They count toward `Dispatcher::CEILING` (Dispatcher.php:19, check at :160) through `recent_counts()`. A sweep is at most 200 carts, so 200 skips stay under 1000 per 60 s and must not pause the workflow.

## Checkpoints

1. **Schema + table** — Risk: 3
   - Files: `Storage/Schema.php`, `Uninstaller.php`, `dev/scripts/reset-data.sh`, `dev/scripts/check-storage.sh` (new asserts: table, both keys), `docs/workflow-core-design.md` (tables list).
   - Tests: UninstallerTest green unchanged; `check-storage --wp-env` and `--wp-env-min` (MariaDB + the min target) show DB 8, `run_emails`, `KEY subject`.
2. **Indexing in the stores + D8** — Risk: 3
   - Files: `Storage/RunStore.php`, `Storage/RunStepStore.php`, new `tests/Unit/Core/Storage/RunEmailsTest.php`.
   - Tests:
     - The extractor: each JSON and text case above, each paired with what `text_regexp()` / `json_like()` would match.
     - Each of the 7 write sites sends one INSERT IGNORE only when an email is present.
     - D8: the index insert returns false or throws → the store call keeps its result, `Logger::error` called.
   - Old tests: O1's one count.
3. **`about()` rewrite** — Risk: 3
   - Files: `Storage/RunStore.php`; `about()`'s own asserts if any (check-runs privacy section).
   - Tests: PHPUnit SQL shape for the three callers; `check-runs` on both wp-env targets; the privacy part of e2e if present.
4. **Sync (D4)** — Risk: 2
   - Files: `Storage/RunStore.php`, tests in `RunEmailsTest.php`.
   - Tests: anonymize deletes only the person's rows of those runs; delete_with_steps order and failure path.
5. **Bench + check (D6)** — Risk: 1
   - Files: new `dev/scripts/lib/privacy-old-scan.php`, `bench-privacy.{sh,php}`, `check-privacy-index.{sh,php}`, `check-all.sh` (list), `dev/README.md` if it lists scripts.
   - Tests: check-privacy-index PASS on both wp-env targets. Bench numbers (50k/200k, EXPLAIN, ms, write cost) go into the plan's Results and the PR text. Fail → stop and report, don't tune silently.
6. **E1: lock on series start** — Risk: 3
   - Files: `Cart/CartSweep.php`, `Storage/CartStore.php`, `Triggers/CartTrigger.php`, `Storage/Schema.php` (carts comment :153), `docs/workflow-core-design.md` (Abandon sweep + carts table lines, §8 hooks line), `docs/workflow-core-decisions.md` (hook change), `CartTriggerNotes.tsx` only if wrong, `dev/scripts/check-carts.php` (O2).
   - Tests: PHPUnit (FakeWpdb):
     - The sweep fires with the kind only when `series_at` is null.
     - `listen` emits only its own kind.
     - The `start_series` SQL holds `series_at IS NULL`; `claim` is false when 0 rows change.
   - check-carts (real DB):
     - I1: a second abandonment after a series fires nothing.
     - I2: unchanged.
     - I3: cart idle → cart kind; later at checkout → checkout kind.
     - I4: only Checkout on → idle cart: no run, no `series_at`; it returns, reaches checkout, idles → a Checkout run.
     - Both on: exactly one run per cart.
7. **E2: Skipped runs** — Risk: 2
   - Files: `Core/Steps/Trigger.php`, `Triggers/CartTrigger.php`, `Runtime/Dispatcher.php`, `Storage/RunStore.php` (create status/error), `tests/Support/FakeTrigger.php` (a settable skip reason), new tests in `DispatcherWorkerTest.php` and a new `tests/Unit/Triggers/CartTriggerTest.php`.
   - Tests:
     - PHPUnit: each reason, one skipped run per listening workflow, none for a workflow of another trigger, none when no workflow is active, null reason → no run.
     - PHPUnit (FakeRunRepository): 200 skipped claims in one dispatch round → 200 skipped runs, the workflow stays active (no `deactivate`), no pause logged.
     - check-carts: a 7-day repeat → a skipped run with the reason, visible through `RunStore::list( status skipped )`, and counted in `RunStore::stats()` (recent, last_status skipped).
8. **.pot, docs, PR text** — Risk: 1
   - Files: `languages/yaymatik.pot` (`pnpm makepot`), `docs/workflow-core-decisions.md` (no HMAC; index-at-write instead of a scan; lock on series start), `.claude/plans/dev/033-pr.md` (summary, bench table, reset-data step, browser steps).
   - Tests: check-all `--wp-env` and `--wp-env-min`, `pnpm e2e`, `./run.sh probe-mysql --staging` (read-only, as is), make-pot without warnings.

Browser check steps, in the PR text (format: 0 Before you start · 1 Setup · 2 Steps (Goal / Do / Pass when / Why) · 3 Results; UI only):
- Only Cart on.
- Only Checkout on: idle cart → nothing; return, go to checkout, leave → a Checkout run.
- Both on: one reminder only.
- Admin repeats a test with the same email within 7 days → a Skipped row with the reason under Skipped.
- Privacy: Tools › Export Personal Data for a customer with runs, one of them Skipped → the export lists them. Then Tools › Erase Personal Data → export again → no runs found.

## Out of Scope
- Extension API (034).
- Backfill or migration of `run_emails` (D5).
- HMAC or hashing of index values (D1).
- UI changes beyond what E2's Skipped rows need (none expected).
- Skip records for silent rejects: missing cart, a lost race, a second abandonment after a series.

## Decisions
- O1 (approved): `RunStepStoreTest::test_finish_sets_finished_at_except_while_waiting` gets its call count changed 2 → 3 (the index INSERT for `a@x.com`). It is the only old PHPUnit edit besides `about()`'s own.
- O2 (approved): the old-lock asserts in `check-carts.php:1110-1132` are rewritten to I1–I4 in CP6 and listed in Deviations.
- O3 (approved): new `Trigger::skip_reason( array $args ): ?string`, default null; `claim(): bool` unchanged.
D1–D6 and D8 as in the brief, confirmed. D7 is not in the brief.
- D1 `run_emails`: plain normalized email, truncated to 190 the same way at write and lookup; claim keys stored too; DB 8; no HMAC.
- D2 Index at all 7 writes; one extractor with the old scan's boundaries; INSERT IGNORE; indexing code only in RunStore and RunStepStore.
- D3 `about()` = UNION over `run_emails` and `runs.subject`; `KEY subject (subject,status)`; OFFSET kept; PersonalData unchanged.
- D4 anonymize and delete_with_steps keep the index in sync; Uninstaller lists the table.
- D5 No backfill; reset-data after deploy.
- D6 bench-privacy + check-privacy-index; the old scan only in dev scripts.
- D8 An indexing failure logs and never fails the run.
- E1 / E2 / I1–I4 as in the brief.

## Deviations
(none yet)

## Meta
- Target branch: jack/feature/033-privacy-index
- Base branch: develop (9b31ca7)
- Next: /wdev-lite, one-pass
