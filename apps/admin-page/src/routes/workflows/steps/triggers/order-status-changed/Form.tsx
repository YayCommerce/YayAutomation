import { useState } from 'react';
import { useForm } from '@tanstack/react-form';
import { __ } from '@wordpress/i18n';

import { Checkbox } from '@/components/ui/checkbox';
import {
  ComboboxBadge,
  ComboboxCheckbox,
  ComboboxIcon,
  ComboboxRemove,
  ComboboxTrigger,
} from '@/components/ui/combobox';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Field, FieldContent, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Popover, PopoverContent } from '@/components/ui/popover';
import { ConfigField } from '../../core/fields/ConfigField';
import { useMarkInvalid } from '../../core/fields/feedback';
import { useValidateOnMount } from '../../core/fields/useValidateOnMount';
import type { StepFormProps } from '../../core/types';
import { schema, statuses, statusLabel, type OrderStatusChangedConfig } from './schema';

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

export function OrderStatusChangedForm({ config, onChange }: StepFormProps<OrderStatusChangedConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);
  const markInvalid = useMarkInvalid();

  return (
    <FieldGroup>
      <form.Field name="from">
        {(field) => (
          <ConfigField
            label={__('Status changes from', 'yay-automation')}
            htmlFor="step-status-from"
            description={__('Leave empty for any previous status.', 'yay-automation')}
            errors={field.state.meta.errors}
          >
            <StatusMultiSelect
              id="step-status-from"
              value={field.state.value}
              onChange={field.handleChange}
              placeholder={__('Any status', 'yay-automation')}
              invalid={markInvalid(field.state.meta)}
            />
          </ConfigField>
        )}
      </form.Field>

      <form.Field name="to">
        {(field) => (
          <ConfigField
            label={__('Status changes to', 'yay-automation')}
            htmlFor="step-status-to"
            description={__('Leave empty for any new status.', 'yay-automation')}
            errors={field.state.meta.errors}
          >
            <StatusMultiSelect
              id="step-status-to"
              value={field.state.value}
              onChange={field.handleChange}
              placeholder={__('Any status', 'yay-automation')}
              invalid={markInvalid(field.state.meta)}
            />
          </ConfigField>
        )}
      </form.Field>

      <form.Field name="only_checkout">
        {(field) => (
          <Field orientation="horizontal">
            <Checkbox
              id="step-status-checkout"
              checked={field.state.value}
              onCheckedChange={(checked) => field.handleChange(checked === true)}
            />
            <FieldContent>
              <FieldLabel htmlFor="step-status-checkout">
                {__('Only run for checkout orders', 'yay-automation')}
              </FieldLabel>
              <FieldDescription>
                {__(
                  'Skips orders created by admin, REST API, import tools, and other non-checkout sources.',
                  'yay-automation',
                )}
              </FieldDescription>
            </FieldContent>
          </Field>
        )}
      </form.Field>
    </FieldGroup>
  );
}
