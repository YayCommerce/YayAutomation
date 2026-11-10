# Plan: 019 — Build and lint tooling

## Goal
`pnpm build` no longer fails at random while `pnpm dev` runs, and the stop hook catches the lint errors CI catches.

## Findings
- vite-plugin-external (6.2.2) writes stand-ins to `node_modules/.vite_external/` in both dev and build. A build can read a half-written `@wordpress_i18n.js` → `"__" is not exported`. 6.2.2 supports `cacheDir` per mode.
- Stop hook runs `pnpm build` + `pnpm test`, never lint. No ESLint cache (`eslint .`, no `--cache`). That is why 018's no-fallthrough (and prettier) slipped to CI.

## Approach
- `apps/admin-page/vite.config.ts`: `externalOptions.production.cacheDir = 'node_modules/.vite_external_build'`. Dev keeps the default.
- Stop hook: `pnpm lint` from repo root (same as CI) when `apps/`/`packages/` change. Hint must not lead to the `components/ui/bubble.tsx` rewrite: make `lint:fix` skip `components/ui` if small, else hint "then revert components/ui/bubble.tsx".
- Stop hook build failure: print the `error during build` block plus a few lines after, not only lines matching "error".
- Update Verify in `.claude/docs/context-dev-convention.md`.

## Checkpoints
1. ✅ Build cache folder — Risk: 1 — config change. Proof: baseline loop (report failure count), then with `pnpm dev` running, 20/20 `pnpm build` pass. Built assets identical before/after (hashes).
2. ✅ Lint in stop hook — Risk: 1 — hook + lint:fix scope + build error output + doc. Proof: scratch fallthrough file under `apps/admin-page/src` blocks the hook; prettier-only problem blocks it; delete scratch, hook passes.

Each checkpoint: mark ✅, report, stop. No commit until Jack says.

## Follow-ups
- Two builds at once (e.g. stop hook + manual `pnpm build`) share `node_modules/.vite_external_build`.
- Scratch-test gotcha: a comment matching /falls?\s?through/i silences no-fallthrough.
- Checked, no action: Vitest (`test` mode) writes no stand-ins — no externals for that mode — so it can't race dev.

## Out of Scope
Upgrade to vite-plugin-external 8 / Vite 7, ESLint cache, PHP lint, CI changes.

## Meta
- Target branch: jack/chore/build-lint-tooling
- Base branch: develop
