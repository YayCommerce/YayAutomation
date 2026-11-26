---
description: Release workflow — bump version, build, and create a distribution zip.
---

# /wdist

Workflow - Create a release distribution.

---

## Step 1 — Version bump

Read the current version from `yaymatik.php`:
- Plugin header comment: `* Version: X.Y.Z`
- PHP constant: `define( 'YAYMATIK_VERSION', 'X.Y.Z' );`

Ask the user what the new version should be (or suggest a patch bump). Show preview:

> Current: `1.1.0` → New: `1.1.1`. Proceed?

Wait for confirmation. Then update **both** the header comment and the constant — they must stay in sync.

---

## Step 2 — Build

```bash
pnpm build
```

If build fails: show last 40 lines, ask user to fix or auto-fix if clearly scoped.

---

## Step 3 — Zip

```bash
./run.sh release
```

---

## Step 4 — Done

> **Release complete: v\<version\>**
>
> Zip: `release/yaymatik-automation-for-woocommerce.zip`

Do not commit the version bump unless the user asks.
