import { useForm } from '@tanstack/react-form';
import { __ } from '@wordpress/i18n';

import { Checkbox } from '@/components/ui/checkbox';
import { Field, FieldContent, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field';
import { ConfigField } from '../../core/fields/ConfigField';
import { useMarkInvalid } from '../../core/fields/feedback';
import { MultiSelect } from '../../core/fields/MultiSelect';
import { useValidateOnMount } from '../../core/fields/useValidateOnMount';
import type { StepFormProps } from '../../core/types';
import { schema, statuses, type OrderStatusChangedConfig } from './schema';

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
            <MultiSelect
              options={statuses}
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
            <MultiSelect
              options={statuses}
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
