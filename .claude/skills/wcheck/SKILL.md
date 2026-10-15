---
description: Pre-ship browser verification — run through a structured checklist in Chrome before calling any frontend task done.
---

# /wcheck

Workflow - Browser verification. Run before marking any frontend task done.

---

## Step 0 — Browser Extension

Ask the user:
1. Is the **Claude in Chrome** extension installed and enabled for this site?
2. What is the **WP admin URL**? (e.g. `https://yaydev.test/wp-admin/admin.php?page=yaymatik`)

Do not proceed until confirmed.

---

## Step 1 — Screenshot

Load the Chrome tools:

```
ToolSearch: select:mcp__claude-in-chrome__tabs_context_mcp,mcp__claude-in-chrome__navigate,mcp__claude-in-chrome__computer,mcp__claude-in-chrome__read_console_messages
```

Navigate to the page and take a screenshot of the current state.

---

## Step 2 — Console errors

Use `read_console_messages` with `onlyErrors: true`. If errors present:
1. Identify source (file, line, message)
2. Fix before continuing
3. Re-verify

---

## Step 3 — Golden path

For each primary action the task introduced or changed:
- Perform the action
- Screenshot result
- Confirm expected outcome visible

---

## Step 4 — Edge cases

List edge cases relevant to the task (empty state, loading state, dark mode, etc.) and test each.

---

## Step 5 — Report

| Check | Result |
|---|---|
| Console errors | None / [list] |
| Golden path | Pass / Fail |
| Edge cases | Pass / Fail |

Fix any failures before reporting done.
