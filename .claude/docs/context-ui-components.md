# UI Components

`apps/admin-page/src/components/ui/` — full YayUiKits set, copied in. Unused ones tree-shake; don't prune.

Source: `../YayUiKits/apps/admin-page/src/components/ui/`. Showcase: `/wp-admin/admin.php?page=yay-ui-kits`.

## Sync from YayUiKits

1. Copy the component file plus missing imports (`variants/`, `hooks/`).
2. New dep → `pnpm -F @yay-automation/admin add <dep>`, same range as `../YayUiKits/apps/admin-page/package.json`.

Don't restyle copies locally — change YayUiKits, then re-copy.

## Rules

- CSS variables only (`src/styles/theme.css`); no `dark:` prefix.
- `cn()` from `@/lib/utils`, `cva` for variants, icons from `lucide-react`.
- Page wrappers: `src/components/widget/PageWidget.tsx`.
