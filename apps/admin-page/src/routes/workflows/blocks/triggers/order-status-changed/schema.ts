import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

/**
 * Mirrors src/Engine/Blocks/Triggers/OrderStatusChanged.php. Statuses are slugs without the `wc-` prefix.
 * Empty `from` means any previous status.
 */
export type OrderStatusChangedConfig = { from: string[]; to: string[] };

export const statuses = window.yayAutomationMeta.wc.orderStatuses;

export const statusLabel = (value: string) => statuses.find((status) => status.value === value)?.label ?? value;

/** Without WooCommerce there's nothing to check against; the PHP block skips the check too. */
const isKnown = (value: string) => statuses.length === 0 || statuses.some((status) => status.value === value);

const unknownStatus = __('Unknown order status.', 'yay-automation');

export const schema = v.object({
  from: v.pipe(
    v.array(v.string()),
    v.check((list) => list.every(isKnown), unknownStatus),
  ),
  to: v.pipe(
    v.array(v.string()),
    v.minLength(1, __('Choose at least one status the order changes to.', 'yay-automation')),
    v.check((list) => list.every(isKnown), unknownStatus),
  ),
});

/**
 * Status list from stored config. Also reads the old single-string format ('any' or one status),
 * like the PHP block's `sanitize()`, so workflows saved before statuses became lists still load.
 */
export function toStatusList(raw: unknown): string[] {
  if (typeof raw === 'string') return raw === 'any' || raw === '' ? [] : [raw];
  if (!Array.isArray(raw)) return [];
  return [...new Set(raw.filter((status): status is string => typeof status === 'string' && status !== ''))];
}

export const defaults = (): OrderStatusChangedConfig => ({ from: [], to: [] });

export const normalize = (config: Record<string, unknown>): OrderStatusChangedConfig => ({
  from: toStatusList(config.from),
  to: toStatusList(config.to),
});
