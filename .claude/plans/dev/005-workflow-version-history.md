# Plan: Workflow version history + runs section

## Goal

The workflow detail page gets a "Version history" tab and a "Workflow runs" section below the tabbed card, matching Shopify Flow.

## Approach

- **Visual references:** `.claude/references/29-version-history.png` (tab), `30-version-history-with-runs.png` (full page incl. runs empty state), `31-current-workflow-detail.png` (current state). YayUiKits components + theme tokens only; dark mode via tokens.
- **Storage:** custom table `{prefix}yay_automation_versions`:
  `id BIGINT PK AI | workflow_id BIGINT (index) | action VARCHAR(20) created|edited|activated|deactivated | graph LONGTEXT (JSON) | user_id BIGINT | created_at DATETIME GMT | updated_at DATETIME GMT`.
  - `dbDelta` when option `yay_automation_db_version` is behind, checked on `plugins_loaded` (covers updates, not just activation).
  - Workflow meta `_yay_automation_live_version` / `_yay_automation_draft_version` point at rows.
- **Recording** (in the existing write paths, under the per-workflow lock):
  - Create → insert `created`; it is the live row.
  - Edit, never turned on → update the live row in place (snapshot, action `edited`, `updated_at`).
  - Edit, ever turned on → update the draft row, or insert `edited` and point the draft at it. Draft equal to live (draft cleared) → delete its row.
  - Turn on → insert `activated` (live snapshot); becomes the live row.
  - Apply → the draft row becomes the live row; draft pointer cleared. No new row.
  - Turn off → insert `deactivated` (live snapshot); becomes the live row.
  - Discard → delete the draft row.
  - Delete workflow → delete all its rows.
  - Pre-existing workflows with no rows: lazily seed one `created` row (`post_author`, `post_date`, live graph) + a draft row if a draft exists, on first read or write.
- **Status column:** "Active" = live row while the workflow is on. "Draft" = the draft row, or the live row of a never-turned-on workflow. Empty otherwise. Computed server-side.
- **REST:** `GET /workflows/:id/versions?page=N` (`can_manage`), 10 per page, `id DESC` → `{ items: [{ id, action, user, createdAt, graph, status }], hasMore }`. `createdAt` = row `updated_at`; `user` = display name.
- **`Workflow.lastVersionAt`:** newest row's `updated_at`. Drives "Last updated" (moves on draft autosave too, per reference 29).
- **Frontend (detail page):**
  - Third tab "Version history" next to the existing tabs. Kit `Table`: Version created | Recent history | Status. Rows non-interactive, but each row is built from one `version` object so preview/restore can hook in later.
  - Version created: "Oct 2 at 3:39 PM" — short date (year only if not the current year) + WP time format. Warning icon when `validateWorkflow` on the row's graph returns issues.
  - Recent history: "<Created|Edited|Activated|Deactivated> by <name>" (translatable, one string per action).
  - Status: kit `Badge` info "Draft" / success "Active".
  - Prev/next arrow pagination.
  - "Last updated on <WP date> at <WP time>" from `lastVersionAt`.
  - Layout: page scrolls; the tabbed card gets a fixed height (~65vh).
  - `WorkflowRuns` component (`runs`, `loading` props) below the card, every tab. No run logging exists, so it gets `runs={[]}` → empty state: local inline SVG illustration, "Workflow runs will show up here", two-line description, "Learn more about monitoring workflow runs" link (href from `yayAutomationMeta`, PHP filter `yay_automation_workflow_runs_docs_url`, placeholder default).

## Checkpoints

1. Backend — Risk: 3 — table + migration, version recording in every write path, delete cleanup, lazy seed, versions endpoint, `lastVersionAt`, docs-URL meta. Test via REST in the console.
2. Frontend — Risk: 2 — API hook, Version history tab + pagination, `WorkflowRuns` empty state, layout, Last updated line. Test in the browser (light + dark).

## Out of Scope

- Clicking a version row to preview or restore it.
- Real run logging / runs table.

## Meta

- Target branch: jack/refactor/workflow-ui-shopify-flow
- Base branch: 003 (PR by hand)
- No commits without asking first.
