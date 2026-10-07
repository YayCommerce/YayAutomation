# Plan: Editor Identity

Status: open, not decided. The assignee picks an approach.

## Goal

The workflow editor was built to mirror Shopify Flow. Code comments say so: `grep -rni shopify apps/admin-page/src` finds about 21 hits.

Give it its own identity, so that:

- **Visual:** no screenshot can be mistaken for Shopify Flow.
- **Wording:** all user-facing copy is in our own voice and uses WordPress/WooCommerce terms. No string matches Shopify Flow's wording.
- **Behavior and layout:** stay the same. The canvas keeps its top-to-bottom flow.

Use YayUiKits (see `.claude/docs/context-ui-components.md`). Design first, then refactor.

## What makes a screenshot recognizable

Ordered from strongest to weakest signal. Layout direction is also a strong signal, but it's out of scope: top-to-bottom is generic and isn't anyone's signature.

1. **Node shape:** Flow uses wide white cards. Make uses circles. n8n uses square tiles with the label below.
2. **Color:** Flow is grey, white and black. Make colors by app. n8n uses a per-node accent.
3. **Lines and branches:** Flow uses S-curves, and True/False tabs inside the condition card.
4. **Copy:** "Turn on", "Review", "Select a trigger", "True/False".

## Current Flow-like traits

| Trait | Where |
| --- | --- |
| Wide white card, muted kind icon | `routes/workflows/shared/canvas/NodeCardView.tsx` |
| Dark outline when selected | `NodeCardView.tsx` |
| True/False tabs inside the condition card | `NodeCardView.tsx` (`branchLeft`) |
| "Review" / "Error" tab above the card | `NodeCardView.tsx`, `pages/editor/IssueBanner.tsx` |
| S-curve lines | `shared/canvas/CurvedEdge.tsx`, `EdgeArrow.tsx`, `flow.ts` |

Interactions like click-to-open or the add buttons are standard UX, so they're not listed.

## Approaches

### A. Restyle only (small)

Keep the layout and card structure. Change colors, the selected state, line style and issue markers to YayUiKits styling.

- **Pros:** fast. No behavior change.
- **Cons:** may still read as "Flow, reskinned".

### B. Borrow specific patterns (medium)

Keep the top-to-bottom layout. Redesign the card and the lines.

Borrow a pattern only if it adds value for the user, like clearer reading, faster editing or less clutter. Being different from Flow is not a reason on its own. Candidates to evaluate:

- **Zapier / n8n:** step numbers; one icon per block (Woo, email, note) instead of a generic kind icon.
- **Make:** branch labels (Yes/No) on the lines, not inside the card.
- **n8n:** a "+" on the line; square lines with rounded corners instead of S-curves.
- **Per-kind accent:** a color stripe or icon tile for trigger, condition and action.

- **Pros:** clearly different, and the UX stays familiar.
- **Cons:** without one design source, it can turn into a mashup.

## Open questions for the assignee

1. **Identity source:** YayUiKits + WP/Woo as the anchor, or a mix of competitor patterns?
2. **Design deliverable before code:** for example, a mock in light and dark mode showing empty, filled, selected and error cards, plus a condition with both branches.
3. **Wording timing:** design it in the same pass? Card text is part of the screenshot. Candidates: "Activate" for "Turn on", "Yes/No" for "True/False", "Needs setup" for "Review".
4. **Sign-off:** who approves the design before refactoring starts?

## Done when

- [ ] Design approved before code changes.
- [ ] A side-by-side screenshot with Shopify Flow is not mistaken for it by someone outside the team.
- [ ] No user-facing string matches Shopify Flow's wording.
- [ ] No "like Shopify Flow" comments left in code.
- [ ] Light and dark mode both checked. Read-only template preview (`shared/canvas/ReadOnlyCanvas.tsx`) matches the editor.
- [ ] Behavior and layout unchanged.
