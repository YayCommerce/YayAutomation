---
description: One-time dev environment check — run after cloning on a new machine to confirm all tools are wired.
---

# /wsetup

One-time setup verification. Not part of the dev loop.

---

## Step 1 — CLI tools

```bash
node --version
pnpm --version
php --version
composer --version
```

Report any missing tools.

---

## Step 2 — Install dependencies

```bash
pnpm install
composer install                 # needs GitHub access to YayCommerce/yaycommerce-plugin-admin-shell
./vendor/bin/yaycommerce-update  # builds vendor-prefixed/ (scoped admin-shell)
```

---

## Step 3 — Build check

```bash
pnpm build
```

---

## Step 4 — PHP lint

```bash
find src yay-automation.php autoloader.php -name '*.php' -exec php -l {} \; | grep -v '^No syntax errors'
```

Empty output = pass.

---

## Step 5 — WordPress

- Plugin active? `wp plugin list --path=../../.. | grep yay-automation`
- Dev mode? `grep YAY_AUTOMATION ../../../wp-config.php` — see `.claude/docs/context-dev-convention.md` → Dev Server.

---

## Step 6 — Chrome extension

Load browser tools:

```
ToolSearch: select:mcp__claude-in-chrome__tabs_context_mcp
```

Call `tabs_context_mcp`. If it returns tab data → extension live. If it errors → not connected.

---

## Done

| Tool | Status |
|------|--------|
| node / pnpm | ✓ / ✗ |
| php | ✓ / ✗ |
| pnpm install | ✓ / ✗ |
| pnpm build | ✓ / ✗ |
| php -l | ✓ / ✗ |
| plugin active | ✓ / ✗ |
| Claude in Chrome | ✓ / ✗ |

One sentence: what's missing and the fix, or "All good — ready to develop."
