# Plan: Improve workflow editor UI (review / error flow, condition, variables)

## Goal

Make the editor's feedback work like Shopify Flow: quiet while building, blue "Review" on steps left unfinished, red "Error" after a failed Turn on / Apply; plus the condition card, the "Add a variable" picker, variables as condition values, and dropping action steps left unchosen.

## Approach

**Visual references** (local only, `.claude/plans/dev/references/`, gitignored): 1 workflow-level errors, 2 step error banner, 3 our condition card, 4 True / False labels, 5 Add a variable, 6 criteria error, 7 condition in review (empty), 8 criteria in review (value missing), 9 variable-as-value picker, 10 variable chosen as value, 11 action in review. YayUiKits components + theme tokens only (light/dark). The theme has no `info` color: "Review" uses `primary` tints (as the old Review header did); "Error" uses `destructive`.

### 1. Feedback modes

| | Before Turn on / Apply (or after errors are cleared) | After Turn on / Apply fails ("error mode") |
|---|---|---|
| Banner | none | red, one issue at a time |
| Step with an issue, never left | nothing | red "Error" header + red fields |
| Step left (panel closed) with an issue | blue "Review" header + blue fields | red "Error" header + red fields |
| New step added | — | red right away if it has an issue |

- Fields: the criteria box or the field gets a light tint (blue / red) and its message sits below in the same color.
- Review mode never shows "Criteria is required" in the panel (a condition with no variable shows an empty box); the card still gets "Review". Error mode shows it.
- **Error mode ends** as soon as no step has an issue (workflow-level issues like "Add an action" don't count): banner gone, Turn on / Apply enabled, back to review behavior. Turn on / Apply stays disabled while error mode is on.

### 2. Workflow-level issues (done)

- `no_trigger` ("Add a trigger to this workflow") and `no_action` ("Add an action to this workflow") have no step; banner only, no View link. PHP + TS.

### 3. Banner (done)

- Wide, pager ‹ › "2 of 2" on the right beside the View link; workflow-level issues show just their message.

### 4. Messages and placeholders

- Required fields of actions use one message, "This field is required" (PHP + TS). Send internal email "To" stays optional (blank = admin email).
- Condition: no variable → "Criteria is required. Add a criteria for comparison, or remove this condition."; value missing → "<Variable> is required." (+ the "Is empty" hint for text).
- Card placeholders: an italic *Empty* replaces "…" in every block's summary.

### 5. Create workflow and trigger picker

- "Select a trigger" in the Create workflow dialog shows a spinner in place of its icon and text while the workflow is created, then the editor opens.
- A new workflow's trigger picker can't be closed until a trigger is chosen (no X, canvas clicks ignored). The swap picker of a chosen trigger closes as usual.

### 6. Condition

- Card: "✓ True" / "✗ False" above the branch tabs (done). Then / Otherwise edge labels stay.
- Picking a variable preselects the type's first operator (done).
- Criteria box header: variable label + key, ⇄ swap (re-opens the picker) and delete.
- **Variable as value** (`</>` in the value input), for number, text and date variables only (option lists keep their select):
  - Opens "Add a variable" filtered to the same type, with a "Types: …" line.
  - Chosen → the value shows as label + key with ⇄ swap and delete (delete returns to a typed value).
  - Config: rule gets `valueType: 'value' | 'variable'`; with `variable`, `value` holds the field key. PHP `Criteria` sanitizes, validates (known, conditional, same type) and compares against the other field's value. Card: "Order total is equal to Order subtotal".

### 6b. Adding and removing criteria

- "Add criteria" → AND / OR adds a box and opens "Add a variable" for it right away. Closed without a pick → that box is removed. Picked → the box shows the variable with the first operator.
- The first box of a new condition does not open the picker by itself, and closing the picker there keeps the box.
- Delete on the only box resets it to empty ("Add a variable"); with two or more boxes it removes that box. A condition always keeps one box.
- ⇄ on a box that has a variable, closed without a pick → nothing changes.

### 6c. Spotlight while picking

- While "Add a variable" is open, the page and the step panel are dimmed except the element being edited (criteria box, value input, or the action field), which stays bright.
- The modal sits right of the panel, aligned vertically with that element, kept on screen.
- Applies everywhere the modal opens: criteria variable, ⇄ swap, `</>` value, action "Add variable".

### 7. Add a variable (done)

- Modal beside the step panel: trigger row, data items (key, description, `>`) → fields, search by name. Shared by criteria and action fields. Item descriptions come from PHP.

### 8. Unchosen actions are dropped (done)

- An action still without a block (and nothing below it) is removed when its panel closes: X, canvas click, or another step.

### 9. Added during manual testing

- **Responsive:** the step panel is 480 / 420 / 360px for a canvas ≥ 1200 / ≥ 900 / narrower (`panelWidthFor`). The graph re-fits into the space beside the panel when the canvas resizes, and the step whose panel opens is panned into view when the panel would hide it (`ViewKeeper` in `WorkflowCanvas`).
- **Spotlight:** the dim layer covers the panel's border (no light edge).
- **Card controls:** "+", swap and delete show only on card hover (padding bridges keep the hover while reaching them), 32px round buttons, fade / grow / slide-in animation (off with reduced motion).
- **Buttons:** "Add a variable" / "Add criteria" tint with the primary color on hover.
- **Action summaries:** configured values in bold (`value()` in `defineBlock`); trigger and condition summaries stay plain.

### 10. Structure

- `WorkflowEditor` keeps the page; `useIssueFeedback` (quiet / review / error, visited steps, card headers) and `useStepPanel` (open step, dropping unchosen actions, locked first picker) hold the state logic.
- `blocks/core/fields/feedback.ts`: feedback context + `useMarkInvalid()` for every form's `aria-invalid`.
- `blocks/conditions/criteria/`: `Form.tsx` (criteria list and boxes) and `ValueInput.tsx` (typed value or variable).
- Docs: `context-php-backend.md` and `context-dev-convention.md` describe the data registry, block registration, Runner and `blocks/core/fields`.

## Checkpoints

1. Issues and errors — Risk: 2 — done: workflow-level issues, banner layout, criteria messages, unchosen actions dropped.
2. Condition card + variable picker — Risk: 2 — done: True / False labels, operator preselect, item descriptions, Add a variable modal.
3. Review / error modes + messages — Risk: 2 — the feedback table above (blue review vs red error, error mode ending when steps are clean), "This field is required", italic *Empty*, Select a trigger spinner, first trigger picker that cannot be closed. Test in browser: build without Turn on (review), Turn on (error), delete the broken steps (back to review).
4. Variable as value, swap, criteria add / remove, spotlight — Risk: 2 — sections 6–6c: `</>` with type-filtered picker, variable value display, ⇄ swap on both sides, PHP sanitize / validate / evaluate. Test in browser + run a workflow comparing two fields.

## Out of Scope

- Test results tab, step descriptions.
- An action added and left unchosen when the editor is closed (not just the panel) stays until removed.
- Variables as values for option-list (enum) variables.
- New triggers, actions or data fields.

## Meta

- Target branch: jack/feature/workflow-editor-ui-improve
- Base branch: jack/feature/workflow-editor-pickers
- No commits without asking first.
