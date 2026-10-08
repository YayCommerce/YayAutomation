import { useForm } from '@tanstack/react-form';
import { __ } from '@wordpress/i18n';

import { FieldGroup } from '@/components/ui/field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ConfigField } from '../../core/fields/ConfigField';
import { useMarkInvalid } from '../../core/fields/feedback';
import { useValidateOnMount } from '../../core/fields/useValidateOnMount';
import type { StepFormProps } from '../../core/types';
import { statuses } from '../../triggers/order-status-changed/schema';
import { schema, type ChangeOrderStatusConfig } from './schema';

export function ChangeOrderStatusForm({ config, onChange }: StepFormProps<ChangeOrderStatusConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);
  const markInvalid = useMarkInvalid();

  return (
    <FieldGroup>
      <form.Field name="status">
        {(field) => (
          <ConfigField
            label={__('New status', 'yay-automation')}
            htmlFor="step-order-status"
            errors={field.state.meta.errors}
          >
            <Select value={field.state.value} onValueChange={field.handleChange}>
              <SelectTrigger id="step-order-status" className="w-full" aria-invalid={markInvalid(field.state.meta)}>
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
