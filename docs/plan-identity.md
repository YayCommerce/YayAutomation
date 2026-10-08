# Plan: Editor Identity

Status: design in review (mock v2). F0 merged 2026-10-08, so code can start; see [Phases](#phases).

## Goal

The workflow editor was built to mirror the product that inspired it (see `.claude/CLAUDE.md`).

Give it its own identity, so that:

- **Visual:** no screenshot can be mistaken for that product.
- **Wording:** all user-facing copy is in our own voice and uses WordPress/WooCommerce terms. No string matches that product's wording.
- **Behavior:** stays the same. The canvas keeps its top-to-bottom flow, its curved lines and arrows.
- **Scope:** every workflow screen, not only the editor. Some page layouts change: create dialog, Template Library, template preview, list row actions (agreed with the leader, 2026-10-08). ROADMAP-v1.0, ROADMAP-dev, design and decisions use the new wording.

Use YayUiKits (see `.claude/docs/context-ui-components.md`). Design first, then refactor.

## What makes a screenshot recognizable

Ordered from strongest to weakest signal. Layout direction is also a strong signal, but it's out of scope: top-to-bottom is generic and isn't anyone's signature.

1. **Node shape:** the reference product uses wide white cards. Make uses circles. n8n uses square tiles with the label below.
2. **Color:** the reference product is grey, white and black. Make colors by app. n8n uses a per-node accent.
3. **Lines and branches:** the reference product uses S-curves, and True/False tabs inside the condition card.
4. **Copy:** "Turn on", "Review", "Select a trigger", "True/False".

## Current traits shared with the reference product

| Trait | Where |
| --- | --- |
| Wide white card, muted kind icon | `routes/workflows/shared/canvas/NodeCardView.tsx` |
| Dark outline when selected | `NodeCardView.tsx` |
| True/False tabs inside the condition card | `NodeCardView.tsx` (`branchLeft`) |
| "Review" / "Error" tab above the card | `NodeCardView.tsx`, `pages/editor/IssueBanner.tsx` |
| S-curve lines | `shared/canvas/CurvedEdge.tsx`, `EdgeArrow.tsx`, `flow.ts` |
| Picker: plugin box with back button, text-only rows | `pages/editor/StepPicker.tsx`, `StepPanel.tsx` |
| "Add a variable" modal with raw ids (`order`, `customer`, `site`) | `steps/core/fields/VariablePicker.tsx`, `AddVariable.tsx` |
| Condition form: "IF", AND/OR select, "Add criteria" | `steps/conditions/criteria/Form.tsx` |
| Start page: illustration, prompt box, "Or", round buttons | `pages/list/CreateWorkflowDialog.tsx` |
| Library: dashed filter pills, text-only cards | `pages/list/library/TemplatesDialog.tsx`, `LibraryFilters.tsx` |
| Template preview: "More templates", floating "About this template", "Install" | `pages/template-preview/TemplatePreviewPage.tsx` |
| Overview: gray pill tabs, runs empty-state copy | `pages/overview/WorkflowOverviewPage.tsx`, `WorkflowRuns.tsx` |
| Dialogs: "Ready to turn on…", "Workflow contains errors…", "Correct the error for this workflow step" | `shared/actions/WorkflowActions.tsx`, `WorkflowOverviewPage.tsx`, `IssueBanner.tsx` |

Interactions like click-to-open or the add buttons are standard UX, so they're not listed.

## Approaches

### A. Restyle only (small)

Keep the layout and card structure. Change colors, the selected state, line style and issue markers to YayUiKits styling.

- **Pros:** fast. No behavior change.
- **Cons:** may still read as the reference product, reskinned.

### B. Borrow specific patterns (medium)

Keep the top-to-bottom layout. Redesign the card and the lines.

Borrow a pattern only if it adds value for the user, like clearer reading, faster editing or less clutter. Being different from the reference product is not a reason on its own. Candidates to evaluate:

- **Zapier / n8n:** step numbers; one icon per block (Woo, email, note) instead of a generic kind icon.
- **Make:** branch labels (Yes/No) on the lines, not inside the card.
- **n8n:** a "+" on the line; square lines with rounded corners instead of S-curves.
- **Per-kind accent:** a color stripe or icon tile for trigger, condition and action.

- **Pros:** clearly different, and the UX stays familiar.
- **Cons:** without one design source, it can turn into a mashup.

## Decisions

Confirmed 2026-10-07.

1. **Approach B.** One design source: YayUiKits tokens + WP/Woo terms. Borrow a pattern only when it helps the user.
2. **Design deliverable:** an HTML mock in light and dark mode covering every workflow screen: list, create dialog, Template Library, template preview, editor and card states, step picker and forms, Insert variable, overview, dialogs. Built with YayUiKits tokens from `src/styles/theme.css`. Mock v2 (current): https://claude.ai/artifact/EK3wTDkQuzEBfDo9TQB97d · v1 kept for comparison: https://claude.ai/artifact/WEQ7wrQwkc8ZjdfBtd2ZMs
3. **Wording:** same pass as the visuals. Update [ROADMAP-v1.0.md](ROADMAP-v1.0.md) to match.
4. **Sign-off:** Jack.
5. **Timing:** design now. Code only after F0 (Blocks → Steps) merges: F0 comes first and touches the same files. Then two phases, see [Phases](#phases).
6. **Workflow list:** follows ROADMAP-v1.0 (M2.10): Runs (30 days) and Last run columns ("—" when never run), Last updated kept, status filter All · Active · Inactive · Draft.
7. **Icons:** step icon = a lucide name from core's list; plugin logo = one SVG per plugin. Recorded as **L5** in [workflow-core-decisions.md](workflow-core-decisions.md) and design §5, §8.

### Proposed changes (to confirm in the mock)

| Today | Proposal |
| --- | --- |
| Grey kind icon | Icon tile, accent per kind (trigger, condition, action); step icon (Woo, email, note) when chosen |
| Dark outline when selected | Primary-color ring |
| "Review" / "Error" tab above the card | Badge on the card's corner |
| True/False row inside the condition card | Yes / No in the same place: green check, grey X. "Then / Otherwise" line labels dropped |
| S-curve lines with arrows, "+" under a free handle | **Kept as today** (decided 2026-10-08: easier to read) |
| Text-only picker rows, back-button box | Step tile per row; "All plugins › WooCommerce" breadcrumb |
| Panel header: bordered icon, "Send internal email • WooCommerce action" | Same tile and eyebrow as the card. A step named after its kind ("Condition") shows only its plugin in the eyebrow, never "CONDITION · Condition" |
| List: trash icon per row | "⋯" menu with the actions that already exist: Edit, Rename, Activate / Deactivate, Delete |
| "Add a variable" button under the field | "Insert variable" on the label row |
| Variable picker with raw ids | Names with step tiles, trigger named, merge-tag key per field |
| Condition "IF / AND / Add criteria" | "Take the Yes path when all of these match" + "Add rule" + "Otherwise the No path runs" |
| Start page: prompt box, "Or", two round buttons | Same flow as the reference product, own look: "What do you want to automate?", example prompts, two start cards (Browse templates, Choose a trigger). Enter opens an assistant panel that shows its progress (stoppable) while placeholder cards pulse on the canvas, then the drafted workflow, with a "Before you activate" checklist. AI box shows "Coming soon" until v2.0 |
| Library: blue hero, dashed filter pills, text cards | Category sidebar with counts and "Show more", plugin search, installed first; filter chips above the grid; cards open with a strip of step tiles, then the description (2 lines); plugin icons only (up to 3 + "+N", name on hover) |
| Preview: floating About box, "More templates" | Breadcrumb to the library; docked side panel: what it does, plugins, next step. **Layout change, this page only** |
| Overview: gray pill tabs, borrowed empty-runs copy | Summary row (status, trigger, runs, last run), underline tabs, own empty state |

Full wording list: see the mock's Wording section. The main ones:

| Today | Proposal |
| --- | --- |
| Turn on / Turn off | Activate / Deactivate |
| Apply changes · Unapplied changes | Update · Draft changes |
| Review | Needs setup |
| True / False, Then / Otherwise | Yes / No |
| Empty (summary placeholder) | Not set |
| Select a trigger | Choose a trigger |
| Add a variable | Insert variable |
| Install | Use template |

## Phases

Decided 2026-10-08.

1. **Phase 1, after F0: frontend restyle.** Canvas and cards, list (Last run shows "Never run" until runs exist), create dialog, Template Library, template preview, overview, dialogs, wording in TS. No backend change needed.
2. **Phase 2, with F1.9–F1.12:** step forms, condition form and Insert variable. F1 replaces today's forms with the generic `StepForm` and changes the data layer to scopes and step outputs, so doing them earlier means doing them twice.
3. **After M2:** real data in Last run and the overview summary row.

### Backend notes for F1

The restyle itself doesn't change stored data: branch ids stay `then` / `otherwise`, only their labels become Yes / No. These items belong in the F1 spec:

All in ROADMAP-dev F1 (confirmed 2026-10-08):

- **Step icon and plugin logo in the catalog** (L5, F1.4).
- **Field name in issues** (F1.6). "Add order note needs a note" needs the missing field; issues have `code`, `message`, `nodeId` only today.
- **New wording in validator and REST messages** (F1.6), e.g. the delete error in `RestAPI.php` ("turned on… Turn it off"). Step / data / plugin labels sent through `yayAutomationMeta` change with their steps.
- **`activatedAt` in workflow responses** (F1.8), for "Active since …" on the overview. Stored already as post meta, not exposed.
- **Read-only "From" line** uses the `notice` widget (F1.9).
- **Runs data** for Runs, Last run and the overview summary (F1.1, F1.8, M2.4).

## Done when

- [ ] Design approved before code changes.
- [ ] A side-by-side screenshot with the reference product is not mistaken for it by someone outside the team.
- [ ] No user-facing string matches the reference product's wording.
- [x] No comments in code name another product (done 2026-10-08).
- [ ] Light and dark mode both checked. Read-only template preview (`shared/canvas/ReadOnlyCanvas.tsx`) matches the editor.
- [ ] Behavior unchanged; canvas layout unchanged. Page layouts match the approved mock.
