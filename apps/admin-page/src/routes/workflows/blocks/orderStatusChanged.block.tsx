import { useState } from 'react';
import { useForm } from '@tanstack/react-form';
import * as v from 'valibot';
import { __, sprintf } from '@wordpress/i18n';

import {
  ComboboxBadge,
  ComboboxCheckbox,
  ComboboxIcon,
  ComboboxRemove,
  ComboboxTrigger,
} from '@/components/ui/combobox';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { FieldGroup } from '@/components/ui/field';
import { Popover, PopoverContent } from '@/components/ui/popover';
import { ConfigField, defineBlock, PLACEHOLDER, useValidateOnMount, type BlockFormProps } from './block';

/**
 * Mirrors src/Engine/Blocks/OrderStatusChanged.php. Statuses are slugs without the `wc-` prefix.
 * Empty `from` means any previous status.
 */
type OrderStatusChangedConfig = { from: string[]; to: string[] };

const statuses = window.yayAutomationMeta.wc.orderStatuses;

const statusLabel = (value: string) => statuses.find((status) => status.value === value)?.label ?? value;

/** Without WooCommerce there's nothing to check against; the PHP block skips the check too. */
const isKnown = (value: string) => statuses.length === 0 || statuses.some((status) => status.value === value);

const unknownStatus = __('Unknown order status.', 'yay-automation');

const schema = v.object({
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
function toStatusList(raw: unknown): string[] {
  if (typeof raw === 'string') return raw === 'any' || raw === '' ? [] : [raw];
  if (!Array.isArray(raw)) return [];
  return [...new Set(raw.filter((status): status is string => typeof status === 'string' && status !== ''))];
}

/** "A", "A or B", "A, B or C". */
function orList(values: string[]) {
  if (values.length < 2) return values.join('');
  /* translators: 1: comma-separated list of statuses, 2: the last status */
  return sprintf(__('%1$s or %2$s', 'yay-automation'), values.slice(0, -1).join(', '), values[values.length - 1]);
}

function StatusMultiSelect({
  id,
  value,
  onChange,
  placeholder,
  invalid,
}: {
  id: string;
  value: string[];
  onChange: (value: string[]) => void;
  placeholder: string;
  invalid: boolean;
}) {
  const [open, setOpen] = useState(false);
  const toggle = (status: string) =>
    onChange(value.includes(status) ? value.filter((s) => s !== status) : [...value, status]);

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <ComboboxTrigger id={id} aria-expanded={open} aria-invalid={invalid} className="w-full min-w-0">
        <span className="flex flex-wrap gap-1">
          {value.length ? (
            value.map((status) => (
              <ComboboxBadge key={status}>
                {statusLabel(status)}
                <ComboboxRemove onRemove={() => toggle(status)} />
              </ComboboxBadge>
            ))
          ) : (
            <span className="text-muted-foreground px-1">{placeholder}</span>
          )}
        </span>
        <ComboboxIcon />
      </ComboboxTrigger>
      <PopoverContent className="w-(--radix-popover-trigger-width) p-0" align="start">
        <Command>
          <CommandInput placeholder={__('Search statuses', 'yay-automation')} />
          <CommandList>
            <CommandEmpty>{__('No status found.', 'yay-automation')}</CommandEmpty>
            <CommandGroup>
              {statuses.map((status) => (
                <CommandItem key={status.value} value={status.label} onSelect={() => toggle(status.value)}>
                  <ComboboxCheckbox selected={value.includes(status.value)} />
                  {status.label}
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  );
}

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
            label={__('Status changes from', 'yay-automation')}
            htmlFor="block-status-from"
            description={__('Leave empty to run whatever the previous status was.', 'yay-automation')}
            errors={field.state.meta.errors}
          >
            <StatusMultiSelect
              id="block-status-from"
              value={field.state.value}
              onChange={field.handleChange}
              placeholder={__('Any status', 'yay-automation')}
              invalid={!field.state.meta.isValid}
            />
          </ConfigField>
        )}
      </form.Field>

      <form.Field name="to">
        {(field) => (
          <ConfigField
            label={__('Status changes to', 'yay-automation')}
            htmlFor="block-status-to"
            errors={field.state.meta.errors}
          >
            <StatusMultiSelect
              id="block-status-to"
              value={field.state.value}
              onChange={field.handleChange}
              placeholder={__('Choose statuses', 'yay-automation')}
              invalid={!field.state.meta.isValid}
            />
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
  defaults: () => ({ from: [], to: [] }),
  normalize: (config) => ({ from: toStatusList(config.from), to: toStatusList(config.to) }),
  summary: ({ from, to }) => {
    const target = to.length ? orList(to.map(statusLabel)) : PLACEHOLDER;
    return from.length
      ? /* translators: 1: previous order status(es), 2: new order status(es) */
        sprintf(__('Order status changes from %1$s to %2$s', 'yay-automation'), orList(from.map(statusLabel)), target)
      : /* translators: %s: new order status(es) */
        sprintf(__('Order status changes to %s', 'yay-automation'), target);
  },
  Form: OrderStatusChangedForm,
});
