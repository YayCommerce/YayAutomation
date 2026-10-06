import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

/**
 * Mirrors includes/Engine/Blocks/Triggers/OrderStatusChanged.php. Statuses are slugs without the
 * `wc-` prefix; empty `from` / `to` means any status.
 */
export type OrderStatusChangedConfig = { from: string[]; to: string[]; only_checkout: boolean };

export const statuses = window.yayAutomationMeta.wc.orderStatuses;

export const statusLabel = (value: string) => statuses.find((status) => status.value === value)?.label ?? value;

/** Without WooCommerce there's nothing to check against; the PHP block skips the check too. */
const isKnown = (value: string) => statuses.length === 0 || statuses.some((status) => status.value === value);

const statusList = v.pipe(
  v.array(v.string()),
  v.check((list) => list.every(isKnown), __('Unknown order status.', 'yay-automation')),
);

export const schema = v.object({ from: statusList, to: statusList, only_checkout: v.boolean() });

const toStatusList = (raw: unknown) =>
  Array.isArray(raw)
    ? [...new Set(raw.filter((status): status is string => typeof status === 'string' && !!status))]
    : [];

export const defaults = (): OrderStatusChangedConfig => ({ from: [], to: [], only_checkout: false });

export const normalize = (config: Record<string, unknown>): OrderStatusChangedConfig => ({
  from: toStatusList(config.from),
  to: toStatusList(config.to),
  only_checkout: !!config.only_checkout,
});
