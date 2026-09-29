import { useForm } from '@tanstack/react-form';
import * as v from 'valibot';
import { __, sprintf } from '@wordpress/i18n';

import { FieldGroup } from '@/components/ui/field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ConfigField, defineBlock, useValidateOnMount, type BlockFormProps } from './block';

/** Mirrors src/Engine/Blocks/OrderStatusChanged.php. Statuses are slugs without the `wc-` prefix. */
type OrderStatusChangedConfig = { from: string; to: string };

const statuses = window.yayAutomationMeta.wc.orderStatuses;

const statusLabel = (value: string) => statuses.find((status) => status.value === value)?.label ?? value;

/** Without WooCommerce there's nothing to check against; the PHP block skips the check too. */
const isKnown = (value: string) => statuses.length === 0 || statuses.some((status) => status.value === value);

const unknownStatus = __('Unknown order status.', 'yay-automation');

const schema = v.object({
  from: v.pipe(
    v.string(),
    v.check((value) => value === 'any' || isKnown(value), unknownStatus),
  ),
  to: v.pipe(
    v.string(),
    v.minLength(1, __('Choose the status the order changes to.', 'yay-automation')),
    // Empty is reported by minLength above; one message is enough.
    v.check((value) => value === '' || isKnown(value), unknownStatus),
  ),
});

function OrderStatusChangedForm({ config, onChange }: BlockFormProps<OrderStatusChangedConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);

  return (
    <FieldGroup>
      <form.Field name="from">
        {(field) => (
          <ConfigField
            label={__('From status', 'yay-automation')}
            htmlFor="block-status-from"
            errors={field.state.meta.errors}
          >
            <Select value={field.state.value} onValueChange={field.handleChange}>
              <SelectTrigger id="block-status-from" aria-invalid={!field.state.meta.isValid}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="any">{__('Any status', 'yay-automation')}</SelectItem>
                {statuses.map((status) => (
                  <SelectItem key={status.value} value={status.value}>
                    {status.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </ConfigField>
        )}
      </form.Field>

      <form.Field name="to">
        {(field) => (
          <ConfigField
            label={__('To status', 'yay-automation')}
            htmlFor="block-status-to"
            errors={field.state.meta.errors}
          >
            <Select value={field.state.value} onValueChange={field.handleChange}>
              <SelectTrigger id="block-status-to" aria-invalid={!field.state.meta.isValid}>
                <SelectValue placeholder={__('Choose a status', 'yay-automation')} />
              </SelectTrigger>
              <SelectContent>
                {statuses.map((status) => (
                  <SelectItem key={status.value} value={status.value}>
                    {status.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </ConfigField>
        )}
      </form.Field>
    </FieldGroup>
  );
}

export const orderStatusChangedBlock = defineBlock<OrderStatusChangedConfig>({
  id: 'order_status_changed',
  kind: 'trigger',
  label: __('Order status changed', 'yay-automation'),
  description: __('Runs when a WooCommerce order moves to a new status.', 'yay-automation'),
  schema,
  defaults: () => ({ from: 'any', to: '' }),
  summary: ({ from, to }) =>
    from === 'any'
      ? /* translators: %s: order status */
        sprintf(__('Order changes to %s', 'yay-automation'), statusLabel(to))
      : /* translators: 1: previous order status, 2: new order status */
        sprintf(__('Order changes from %1$s to %2$s', 'yay-automation'), statusLabel(from), statusLabel(to)),
  Form: OrderStatusChangedForm,
});
