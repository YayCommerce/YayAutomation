# Schedule v1.0 (private)

Personal planning, not team docs. Kept out of git (`.claude/plans/dev/`). Source: docs/ROADMAP-dev.md (build order M1 → M2 → F2 → M7 → M8 → M3 → M4 → M5 → M6 → M9), docs/ROADMAP-v1.0.md, docs/workflow-core-design.md, plans 008–029.

**Release date: set by Jack (Nov 4, 11:08: release timing is not a factor in planning).** The earlier target (Fri Nov 6, 18 templates, nothing cut; Jack, Mon Nov 2 10:43) is replaced. **18 of 18 templates merged (Nov 4, #35 f747ff8).** Left: **030 Additional Triggers, Conditions and Actions** (custom workflows; `decisions-triggers-actions.md`), then **031 M9 release hardening**, then RC and release.

Mon–Fri work days. Pace so far: F1 (sized L ≈ 3 weeks) took Oct 9–15; M1 (planned 5 days) Oct 19–21; M2 (planned 5 days) Oct 22–23; F2 (planned 1 day) Oct 26 morning; M7 (planned 3 days) Oct 27 – Nov 1; M8 (planned 1.5 days) Nov 2; M3–M5 (planned 1 day) Nov 3 morning; M6 (planned 1 day) Nov 3 pm – Nov 4 morning.

## Levers
- One-pass mode in Claude Code (Nov 2): CPs of a run in one go, local commit per CP, one report + one zip review per run, "push" after review (rules in `CLAUDE.local.md`; `git commit` allowed, push / gh pr create / reset / rebase ask). Plan 030: one plan, one PR, 3 runs.
- Stacked branches: PR B starts from PR A's tip as soon as PR A's report is in, while the zip is reviewed; merge develop into B after A merges.
- Khoa in parallel from Mon Nov 2 (proposal, Jack to ask): M9.1 template tests as each milestone merges.
- `/code-review high` only on risk-2/3 CPs; browser checks batched per run.
- Decisions checked against docs, code, WooCommerce 11.2.1 source and AutomateWoo 6.9.4 source before /wplan.

## Done
| Dates | Work | PR |
|---|---|---|
| Oct 9 | F1.0–F1.3: PHPUnit, storage, data layer v2, templates tier 1 | A |
| Oct 10–13 | F1.4–F1.8: step contract + 7 PHP steps, Validator v2, modules, executor, runs REST; merge PR A | A #13 |
| Oct 14 | Identity phase 1; merge PR B | B |
| Oct 15 | F1.9–F1.12 + identity phase 2 (PR C #15); F1 regression (015, PR #16); rename YayAutomation → YayMatik (016) | C, 015, 016 |
| Fri Oct 16 | Post-F1 cleanup: plan 016 follow-ups; mail catcher; M1 decisions; /wplan M1 | 017 |
| Mon–Wed Oct 19–21 | M1 backend (M1.1–M1.6) + UI + First-order thank-you ×2 (M1.7–M1.12); build/lint tooling | 018 #19 #22, 019 #20 |
| Wed–Thu Oct 21–22 | Leader review: conventions, workflow modules, cleanup sweep | 020 #23, 021 #24, 022 #25 |
| Thu–Fri Oct 22–23 | M2 backend (M2.1–M2.5) + UI + Cross-sell / upsell ×4 (M2.6–M2.10); merged Mon Oct 26 | 023 #26, 024 #27 |
| Mon Oct 26 | Extension filters for integrations | #28 |
| Mon Oct 26 | F2 workflows table (F2.1–F2.4): tables, WorkflowStore, transactions, shared lock, layout split; dev data reset | 025 #29 |
| Oct 27–29 | M7 PR A: cart tracking (classic + block), restore link, Settings storage + REST, retention + privacy, Health check REST (M7.1–M7.5, M7.8); CI run locally (org Actions billing) | 026 #30 |
| Oct 29 – Nov 1 | M7 PR B: Settings page, Health check panel, sticky header (M7.6, M7.7); merged Mon Nov 2 as cfb9337. Zip check Nov 2: lint 3 old warnings, phpcs 106 files clean, Vitest 261, PHPUnit 529, build OK | 026 #31 |
| Mon Nov 2 | M8 PR A: schema v6, abandon sweep, Cart / Checkout abandoned triggers, claim (one series per email per 7 days), stop on order, cart data + email blocks, preview (M8.1–M8.5); merged as e3df6e8 | 027 #32 |
| Mon Nov 2 | M8 PR B: trigger panels, Activate issue, library badge, Before you start, 5 Recover carts templates, canvas scroll, stop on unsubscribe (M8.6–M8.8); merged as 374260a. Vitest 313, PHPUnit 561, check-carts 194; browser checks all passed | 027 #33 |
| Tue Nov 3 | 028 M3 Review request ×2, M4 Welcome ×2 (Customer account created trigger, data-store guard, billing fill), M5 High-value alert on paid (Processing / Completed); EmailButton, site blocks, flow.ts; merged as 8a94a41. PHPUnit 570, Vitest 346, check-lifecycle 65; browser checks 16/16 (step 8 n/a). Summary doc https://claude.ai/artifact/YME1B8QAULjerSwNYuih6H | 028 #34 |
| Nov 3–4 | 029 M6 Win-back ×2: Customer inactive daily sweep (last real order N days, 7-day window, batches), `trigger_claims` (DB 7), health row; merged as f747ff8. PHPUnit 590, Vitest 363, check-winback 65 on HPOS and posts; browser checks 12/12. Findings: second win-back version re-emails the window (v1.1 backlog); check-carts left queued runs (script fixed) | 029 #35 |
| Wed Nov 4 | 030 decisions D1–D18 (catalog compared with Shopify Flow / Klaviyo / AutomateWoo 6.9.4 source; hooks checked in WC 11.2.1 source) | — |
| Nov 5–9 | 030 custom workflow catalog: triggers, conditions + groups, actions, Wait until, Stop waiting runs; merged Mon Nov 9 as 8b5c7f5 | 030 #37 |
| Nov 5–10 | 031 staging deploy, mail log, Playwright e2e on wp-env; merged Tue Nov 10 as fb0ea8a | 031 #38 |

## Plan
| Order | Work | Plan / PR |
|---|---|---|
| 1 | Mock frames for 030 UI (Condition form, groups, Reviews picker group, new panels) | — |
| 2 | ✅ 030 /wplan, then run 1 (CP0–CP6: hardening, data + Condition), run 2 (CP7–CP10: order / customer / review triggers), run 3 (CP11–CP16: write actions, Wait until, Stop waiting runs, check script); one PR | 030 #37 |
| 3 | ⏳ M9 (renumbered 032; runs 1–3 + CP11 built on jack/feature/032-m9-release, not merged): version 1.0.0, Requires Plugins header, translation file, readme, M9.3; M9.1 end-to-end test of 18 templates (classic / block checkout, light / dark admin) plus custom-workflow catalog checks | 031 |
| 4 | RC, RC fixes, release build, release (date by Jack) | — |
| Next feature plan | Manual "Test run on an order" (V11.5, dry run) | — |

## How each plan runs
| Step | Jack | Chat |
|---|---|---|
| Decide | Confirm the proposals | Check proposals against docs, code, WooCommerce and AutomateWoo source; table follows / silent / differs |
| Plan | `/wplan NNN` in a new session; paste the plan here | Check it against docs and decisions; one fix prompt if needed |
| Build | `/wdev-lite <plan> — CPa to CPn in one pass` (030: 3 runs) | — |
| Review | Send the report + zip after each run | Rerun the 6 CI steps, check "fixed" claims in the diffs, `git status` (no AM), browser steps, fix prompt if needed |
| Ship | Browser checks → "push" → open PR by hand (merge commit) → merge | Summary doc (M8 / 028 style); mock frames → Built; Project progress + schedule updated |

## UI mock (YayMatik Pages v3) — the UI every plan builds to
Mock: https://claude.ai/artifact/R6XzCEZqqyVHBe7uaknMoS. Authority: plan decisions → docs → mock; a decision that differs from a frame is written into the mock the same day, before the code.
| Plan | Draw missing frames by | To add | Decide first |
|---|---|---|---|
| 026 PR B | done Oct 28 | — | — (merged Nov 2, frames Built) |
| 027 M8 | done | — (Built, #32 #33; mock updated Nov 3) | — |
| 028 M3–M5 | done (#m3m5) | — (Built, #34; overrides: alert From Pending / On hold / Failed, To Processing / Completed; review Condition before Wait; W-3 account button; Account created panel no Footer; field hints not built) | — |
| 029 M6 | done (#m6) | — (Built, #35) | — |
| 030 catalog | before run 1 | Condition form (list rules, relative date, meta key, search field), condition groups, Reviews picker group, panels of new triggers / actions (Wait until, Stop waiting runs, Change customer role, Update customer custom field, Resend email) | — |
| 031 M9 | — | nothing new; M9.1 uses every frame (light / dark) as the checklist; all frames → Built | — |
- Every UI checkpoint prompt: "build to section #id of YayMatik Pages v3; overrides: …". Browser check compares the screen with the frame: difference = bug (fix prompt) or decision (mock updated same day).
- A PR that changes a Built screen redraws its frame before merge.

## Decisions (by date, proposal in brackets)
- Oct 16: thank-you coupon values [10% / 30 days; free shipping / 30 days]; customer email To editable [yes, default customer email]; "Most popular" fixed order. ✅
- Oct 22: Wait units minutes / hours / days / weeks. ✅
- Oct 26: old post type: reset, no migration. ✅ (F2)
- Oct 26: release checklist for v1.0 = version 1.0.0, "Requires Plugins: woocommerce" in the plugin header, translation file, readme. Not in v1.0: license + automatic updates, onboarding, user docs, uninstall cleanup. ✅
- Oct 26: messages.ts cleanup stays after v1.0, not before the release. ✅
- Oct 26: WooCommerce's experimental Abandoned cart recovery (removed in WC 11.2), Wishlists and Save for later (WC 10.9+, block-only, logged-in only, internal API, no hooks) are not used: YayMatik must work on the latest WooCommerce. Shopper Lists → v1.1 / v1.2 once stable with hooks. ✅
- Oct 26–27: M7 plan 026 D1–D13 (carts table, capture any email, restore link, cleanup, privacy, health check; cart tracking off by default; abandoned after 60 minutes). ✅
- Oct 28: mock v3 aligned with plan 026. ✅
- Oct 28: Health check stays in v1.0 (Jack, 11:21). ✅
- Oct 28: M8 UI decisions: exit on order always on, a note in the cart trigger panels, no setting (ROADMAP-dev M8.7 → note); cart tracking off blocks Activate (M1.10 pattern); cart run subject = shopper email; run stopped by an order = Cancelled, "The shopper placed order #N." ✅
- Mon Nov 2: nothing cut; release Fri Nov 6 with 18 templates (Jack, 10:43). ✅ (date replaced Nov 4)
- Mon Nov 2: one-pass mode for Claude Code (Jack, 10:45). ✅
- Mon Nov 2: M8 decisions D1–D14 (plan 027): D3 claims → M6, D5 one series per email per 7 days via `series_at`, D6 series goes on when the shopper returns, D10 snapshot prices; unsubscribe stops a cart run. ✅ (#32, #33)
- Tue Nov 3: 028 decisions H1, R1–R4, W1–W6 (9 changes to the Nov 2 proposal); high-value alert on Processing or Completed; run label after erase kept (live account email). ✅ (#34)
- Tue Nov 3: 029 decisions D1–D10 (chat decided at Jack's request); D1 revised to "last real order" (pending, failed, cancelled don't count). ✅ (#35)
- **Wed Nov 4: release timing no longer a planning factor; Jack sets the date (11:08).** ✅
- **Wed Nov 4: plan 030 "Additional Triggers, Conditions and Actions" before M9; M9 renumbered 031 (15:14).** ✅
- Wed Nov 4: 030 decisions D1–D18 (`decisions-triggers-actions.md`): 6 triggers, ~25 condition fields + list / relative date / meta / search / groups, 6 actions; no Slack, HTTP (v1.2 Pro), stock trigger, tags, Change workflow status; one plan in 3 runs. ✅

## After v1.0
- Not scheduled: TS messages.ts reads PHP messages from yayMatikMeta (own branch), server-side paging for GET /workflows, list Validator per load, Insert variable arrow keys, kind tokens → YayUiKits.
- Next feature plan: Manual "Test run on an order" (V11.5, dry run).
- v1.1 backlog: "Run once per order" via `trigger_claims` (high-value re-alert, same order completed again); Activate warning / per-template claim for a second win-back version; Customer inactive stop when the customer orders during a Wait; Run limit per workflow; customer tags with a place to see them; stock triggers once a channel exists; Workflow run failed trigger; Random split condition; multi-currency totals.
- Cross-sell subjects "Picked for you, {{ customer.first_name }}" read oddly with no name.
- Raised Oct 26, only to discuss with the leader after v1.0 (not decided): Wait form text from its catalog field; checks written in both PHP and TS (L2); splitting long files (Field.php, RestAPI.php, WorkflowActions.tsx, WorkflowEditor.tsx); Vitest file layout.

## Trello cards
One card per phase, one checklist per card, about one item per working day.

**Workflow Core foundation (F1)** — done. Checked item added: Rename YayAutomation → YayMatik.

**Post-F1 cleanup** · Fri Oct 16 · checklist Cleanup — done
- ✅ Merge rename PR into develop
- ✅ Fix plan 016 follow-ups: prod script deps (wp-date), JS translations, REST namespace constant, scoper.inc.php tool-owned, check-orders self-contained
- ✅ Set up local mail catcher (Mailpit)
- ✅ Confirm M1 decisions; write M1 plan

**Customer emails + coupons** · Oct 19–21 · checklist M1 — done
- ✅ Unsubscribe: storage, token link, confirmation page, one-click headers (M1.3)
- ✅ Send email step in WooCommerce email look; yes/no fields first order, unsubscribed (M1.1, M1.4)
- ✅ Generate coupon step, coupon box block, Preview + Send test REST; merge backend PR (M1.2, M1.5, M1.6)
- ✅ Send email panel with Preview and Send test; Generate coupon form; Condition Is true / Is false (M1.7–M1.9)
- ✅ Coupon warning before Activate; Template Library filters and preview; First-order thank-you templates ×2; merge UI PR (M1.10–M1.12)

**Wait + run history** · Oct 22–26 · checklist M2 — done
- ✅ Wait step with resume (M2.1)
- ✅ Cancel waiting runs on Deactivate / Update; runs stop after turning off (M2.2)
- ✅ Runs REST: status filter, paging, step results; list stats (M2.3, M2.4)
- ✅ Product grid email block; merge backend PR (M2.5)
- ✅ Wait form and card; overview runs table and run detail; Deactivate / Update dialog with waiting count (M2.6–M2.9)
- ✅ Workflow list Runs and Last run columns; Cross-sell / upsell templates ×4; merge UI PR (M2.10)

**Workflows table** · Mon Oct 26 · checklist F2 — done
- ✅ workflows + workflow_versions tables, repository rewrite (F2.1)
- ✅ Remove the old post type; check list, editor, versions and runs (F2.2, F2.3)
- ✅ Split graph layout from logic, one graph helper; merge (F2.4)

**Cart tracking + Settings + Health check** · Oct 27 – Nov 2 · checklist M7 — done
- ✅ Cart tracking for customers and guests, email capture on classic + block checkout (M7.1) — PR #30
- ✅ Restore cart link and store front message (M7.2, M7.8) — PR #30
- ✅ Settings storage, REST and Settings page (M7.3, M7.6) — PR #30, #31
- ✅ Data retention, privacy export / erase; Health check and panel; merge (M7.4, M7.5, M7.7) — PR #30, #31

**Abandoned cart + checkout** · Nov 2 · checklist M8 — done
- ✅ Cart sweep, cart data type (M8.1, M8.2) — PR #32
- ✅ Cart abandoned + Checkout abandoned triggers, stop when an order is placed (M8.3, M8.4) — PR #32
- ✅ Cart email blocks, trigger panels, cart tracking badge; Abandoned cart ×3, Abandoned checkout ×2; merge (M8.5–M8.8) — PR #32, #33

**Lifecycle templates** · Nov 3–4 · checklist M3–M6 — done
- ✅ Review request: not-reviewed data, review block, Before you start; templates ×2 (M3) — PR #34
- ✅ Welcome series: Customer account created trigger, welcome block; templates ×2 (M4) — PR #34
- ✅ High-value order alert when a large order is paid (Processing or Completed); template ×1 (M5) — PR #34
- ✅ Win-back: Customer inactive daily trigger, trigger claims; templates ×2; merge (M6) — PR #35

**Custom workflow catalog (030)** · checklist 030
- ✅ Decisions: catalog compared with Shopify Flow, Klaviyo, AutomateWoo; hooks checked in WooCommerce source
- Mock frames for the new Condition form, groups and panels
- ✅ Run 1: safety fixes, new condition data and comparisons, condition groups — PR #37
- ✅ Run 2: Order paid, Order refunded, Order note added, customer milestones, Review posted — PR #37
- ✅ Run 3: customer custom field, customer role, resend WooCommerce email, Wait until, Stop waiting runs; merge — PR #37

**Release hardening (031)** · checklist M9
- ⏳ Translation file, readme, plugin header, version 1.0.0 (M9.2, M9.4) — built in plan 032, not merged
- ⏳ Turn off unfinished modules (M9.3) — built in plan 032, not merged
- v1.0 release candidate
- End-to-end test of all 18 templates and the new catalog: classic / block checkout, light / dark admin (M9.1)

**v1.0 release** · date by Jack · checklist Release
- Fix RC issues
- Final release build
- Release

**v1.0 decisions** · checklist Decisions — same list as "Decisions" above.

## Daily Slack ("Doing: YayMatik")
| Day | Doing |
|---|---|
| Fri Oct 16 | Post-F1 cleanup: plan 016 follow-ups · mail catcher · M1 decisions and plan |
| Mon Oct 19 | M1: Unsubscribe (storage, link, confirmation page) |
| Tue Oct 20 | M1: Send email step (WooCommerce look); first order / unsubscribed fields |
| Wed Oct 21 | M1: Generate coupon, coupon box, Preview + Send test; backend PR review |
| Thu Oct 22 | M1: Send email panel, Generate coupon form, yes/no conditions |
| Fri Oct 23 | M1: coupon warning, Template Library, First-order thank-you templates; UI PR; re-estimate |
| Mon Oct 26 | F2: workflows table (tables, transactions, lock, layout split), merged; M7 decisions |
| Tue Oct 27 | M7: cart tracking (customers, guests, classic + block checkout), restore link |
| Wed Oct 28 | M7: restore cart link, retention / privacy export and erase, Health check REST; backend PR |
| Thu Oct 29 | M7: Settings page, Health check panel, store front message; regression; UI PR |
| Mon Nov 2 | M7 UI PR merged; M8: abandoned cart decisions, plan, cart sweep + triggers |
| Tue Nov 3 | M3 Review request, M4 Welcome series, M5 High-value order alert merged (#34); M6 Win-back decisions |
| Wed Nov 4 | M6 Win-back merged (#35, 18/18 templates); custom workflow catalog decisions (plan 030) |
| Next days | 030: mock frames, /wplan, run 1 → run 3; then 031 M9 |
