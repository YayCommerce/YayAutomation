import { defineStep } from '../../core/defineStep';

/**
 * Form and checks come from includes/Workflow/Steps/Triggers/OrderStatusChanged.php `fields()`.
 * Statuses are slugs without the `wc-` prefix; empty `from` / `to` means any status.
 */
export const orderStatusChangedStep = defineStep({
  id: 'order_status_changed',
  kind: 'trigger',
  // Mirrors the PHP step's own `sanitize()`: unique slugs only.
  normalize: (config) => ({
    from: toStatusList(config.from),
    to: toStatusList(config.to),
    only_checkout: !!config.only_checkout,
  }),
});

/** PHP `OrderData::status_slug()`: `sanitize_key()`, then without the `wc-` prefix. */
const toSlug = (status: string) =>
  status
    .toLowerCase()
    .replace(/[^a-z0-9_-]/g, '')
    .replace(/^wc-/, '');

function toStatusList(raw: unknown) {
  if (!Array.isArray(raw)) return [];
  const slugs = raw.filter((status): status is string => typeof status === 'string').map(toSlug);
  return [...new Set(slugs.filter(Boolean))];
}
