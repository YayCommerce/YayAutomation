# Plan: F1 regression fixes + Template Library layout

## Goal
Fix the 13 regressions from the F1 manual pass and code review, and re-lay out the Template Library to match the screenshot.

## Approach
Every item was confirmed in the code first (none skipped). Small, independent fixes grouped into five checkpoints. No `components/ui` edits; only backend change is the `fieldRequired` message. All new strings use the curly apostrophe (’).

## Checkpoints
1. Editor data and focus — Risk: 2 — ✅
   - R1: `WorkflowEditor` keeps a `formVersion` counter, bumped by `useStepPanel.reset()` after `autosave.reset()`; the whole `StepPanel` is keyed by it (covers `CriteriaForm` and the panel's own swap state; picking resets to settings). If the open panel's node no longer exists after the reset (a draft-only step), close the panel. Test with `react-dom/client` + `act` (happy-dom, no new dep): reset shows the reset value; a draft-only step's panel closes.
   - R2: `VariablePicker` remembers a pick; new optional `focusAfterPick` runs in `onCloseAutoFocus` after a pick. `InsertVariable` focuses the input and puts the cursor after the tag there (rAF removed). Esc / outside click / pickers without the prop → opener.
2. Messages and wording — Risk: 1 (PHP for R7) — ✅
   - R3 DeactivateDialog: "New events won’t start it. Runs already under way will finish. You can activate it again at any time." Plural with "them".
   - R4: prefix an issue with its step label via `sprintf(__('%1$s: %2$s'))` + translators comment, unless the message already contains it. Labels from the graph the issue came from (live issues → `workflow.graph`, draft → `workflow.draft`; editor toast → form graph). Tests in `validation.test.ts`.
   - R6 DeleteStepDialog: `count` → "Delete this step?" / "Delete %d steps?", "This can’t be undone."
   - R7 `fieldRequired` → "This field is required." (Messages.php + model/messages.ts), `./run.sh meta-fixture`.
   - R11 ThemeToggle strings in `__()`. R12 preview info line → "Using it creates a draft. Review it, then activate it."
3. Editor and list UX — Risk: 1 — ✅
   - R5 "Change trigger" ⇄ on the trigger panel (existing picker + confirm). R8 "Needs setup" badge + tooltip on Draft/Inactive rows with `activateIssues()`. R9 kit Tooltip on Change action, Change trigger, Delete step, Remove rule. R10 `color-scheme` on `.yay-dark` / `.yay-light`.
4. Template Library dialog — Risk: 1 — ✅
   - Size like CreateWorkflowDialog; grid `repeat(auto-fill,minmax(280px,1fr))`; narrow layout unchanged. Header: title left, search centered `w-[min(100%,30rem)]` (own row when narrow), × right. Kit Tooltip (`side="top"`, `z-100005`) on plugin icons, plugin "+N", step tiles and step "+N".
   - Sidebar grows: w-55, xl:w-64, 2xl:w-72.
   - Clear (×) button in "Search templates" and "Find a plugin" while they have text (InputGroupButton, "Clear search"; refocuses).
   - Empty results like mock .empty: 72×52 tile, 16px title, 13px description, centred; search → "No templates match “{query}”." + Clear search; filters only → "Try a different filter." + Clear filters; both → both buttons.
4b. Workflow list (mock v2) — Risk: 1 — ✅
   - Tabs and table in one bordered card (rounded-[10px], p-3); small segmented tab bar (bg-muted, rounded-lg, p-[3px]; items 13px, rounded-md, px-2.5 py-0.75; active bg-card + light shadow).
   - Row menu icons: Edit Pencil, Rename Type, Activate/Deactivate Power, Delete Trash2 red.
   - "Draft changes" (list + editor header) and list "Needs setup": mock .badge.warning (#fef3c7/#92400e, dark #793207/#fde68a, rounded-md h-5 px-2 text-xs font-medium); app tokens in theme.css if none match.
   - Status badges (list, editor header, overview, version history): rounded-md; Active success/15 + success-soft-foreground, Inactive muted + muted-foreground-600, Draft primary/14 + link.
   - "Needs setup" (list only) = step card pill: bg-warning text-warning-foreground, CircleAlert, rounded-md. **Deviation from mock v2 (agreed):** dark "Draft changes" bg = amber 16% (#3a2c17 on the card) with #fde68a text, not the mock's #793207, which is the kit's dark --warning used by "Needs setup" (same as the card pill).
   - Keep "—" in Runs / Last run and the WP date format.
5. Dev script and docs — Risk: 1 — ✅
   - R13 `check-storage.sh` lines 41–45: future `wait_until` refused, past claimed, claim keeps `wait_until`. ROADMAP-dev F1 note "regression pass done"; plan-identity wording list only if affected.

Each checkpoint: pnpm build, pnpm test, pnpm lint (+ PHPUnit, phpcs for PHP). Hand-check list light/dark (Library ~1920/1280/900/600). Mark ✅, report, stop. One commit per checkpoint, only when the user says.

## Out of Scope
Full cancel on Deactivate (M2.2); backend beyond Messages.php; `components/ui` edits.

## Meta
- Target branch: jack/fix/f1-regression
- Base branch: develop
