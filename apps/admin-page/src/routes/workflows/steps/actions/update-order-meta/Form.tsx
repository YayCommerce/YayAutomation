import { useRef } from 'react';
import { useForm } from '@tanstack/react-form';
import { __ } from '@wordpress/i18n';

import { FieldGroup } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { AddVariable } from '../../core/fields/AddVariable';
import { ConfigField } from '../../core/fields/ConfigField';
import { useMarkInvalid } from '../../core/fields/feedback';
import { useValidateOnMount } from '../../core/fields/useValidateOnMount';
import type { StepFormProps } from '../../core/types';
import { schema, type UpdateOrderMetaConfig } from './schema';

export function UpdateOrderMetaForm({ config, onChange }: StepFormProps<UpdateOrderMetaConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);
  const markInvalid = useMarkInvalid();
  const valueRef = useRef<HTMLInputElement>(null);

  return (
    <FieldGroup>
      <form.Field name="key">
        {(field) => (
          <ConfigField
            label={__('Field name', 'yay-automation')}
            htmlFor="step-meta-key"
            description={__('The meta key, e.g. _vip_order.', 'yay-automation')}
            errors={field.state.meta.errors}
          >
            <Input
              id="step-meta-key"
              value={field.state.value}
              onChange={(event) => field.handleChange(event.target.value)}
              onBlur={field.handleBlur}
              aria-invalid={markInvalid(field.state.meta)}
            />
          </ConfigField>
        )}
      </form.Field>

      <form.Field name="value">
        {(field) => (
          <ConfigField label={__('Value', 'yay-automation')} htmlFor="step-meta-value" errors={field.state.meta.errors}>
            <Input
              ref={valueRef}
              id="step-meta-value"
              value={field.state.value}
              onChange={(event) => field.handleChange(event.target.value)}
              onBlur={field.handleBlur}
              aria-invalid={markInvalid(field.state.meta)}
            />
            <AddVariable inputRef={valueRef} value={field.state.value} onChange={field.handleChange} />
          </ConfigField>
        )}
      </form.Field>
    </FieldGroup>
  );
}
