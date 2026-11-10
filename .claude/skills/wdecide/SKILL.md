---
name: wdecide
description: Put open decisions to the user in one short list they answer in one reply, and track the answers until "go". Use whenever you need the user to decide something (in /wplan, /wdev or any task), and when the user types "/wdecide", "opens" or "opens?".
---

# /wdecide

Decisions the user makes, in a list they answer in one short reply. Their attention is the bottleneck: decisions never wait behind tool calls, and a list never asks for more than they can weigh at once.

## Words

- **Open**: a decision still waiting for the user. **Confirmed** / **rejected**: answered.
- **High stake**: costly to redo if wrong, or other opens hang on it. **Low stake**: everything else.
- **Held**: confirmed in chat, not yet written to the plan. **go**: write every held decision.

Keep each open's number for the whole session. Never renumber.

## Show

```
confirmed: 1 4 5 (2 held unwritten)
rejected: 2

**3. <title>** (high stake)
- Context: <what exists today, file:line if useful>
- Why you: <why this needs the user and not a default>
- Options: a) … b) …
- Recommend: <a|b>: <why>; cost if wrong: <what breaks or needs redoing>

Later: **7.** <title>: recommend <choice> (high stake, after 3) · **8.** <title>: recommend <choice>
```

- At most 6 full blocks, and only for high-stake opens the user can decide now.
- One line each, at the end: low-stake opens, high-stake opens past the 6, and any open whose recommendation could change once another open is answered (say which: "after 3").
- When one open's answer could change another's recommendation, the first one shows first.
- Confirmed and rejected stay on one line each. Write enough context to decide without scrolling up; don't pad.
- The multiple-choice question tool: only for at most 2 trivial choices.

## Reply grammar

- `3y` accept the recommendation · `3n` reject · `3b` pick option b · `3?` explain more.
- Text after an item is a note on it: `3y check readable code`, `7 explain why X is not ok`.
- Items the reply doesn't mention stay open.
- `go`: write all held decisions to the plan's `## Decisions` in one edit.

## Turns

- Showing opens ends the turn: research first, then one reply. No edits or tool calls after it.
- While opens are discussed, the ledger lives in chat; no file edits. At 5+ held, suggest `go`.
- A reply that asks for `opens`: print only the list, counting that reply's confirms. Change nothing that turn. Held confirms apply on the next reply without `opens`, or on `go`.
- An open that blocks the next step stops the work. One that doesn't goes in the plan's `## Decisions` as open, work continues, and the user answers them in one batch.
- After a reply: at most 5 lines on what changed, plus the open count, unless the reply leaves opens to show.
