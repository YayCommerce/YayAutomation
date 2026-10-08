import { useForm } from '@tanstack/react-form';
import { __ } from '@wordpress/i18n';

import { FieldGroup } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ConfigField } from '../../core/fields/ConfigField';
import { useMarkInvalid } from '../../core/fields/feedback';
import { useValidateOnMount } from '../../core/fields/useValidateOnMount';
import type { StepFormProps } from '../../core/types';
import { schema, type WaitConfig, type WaitUnit } from './schema';

const unitLabels: Record<WaitUnit, string> = {
  minutes: __('Minutes', 'yay-automation'),
  hours: __('Hours', 'yay-automation'),
  days: __('Days', 'yay-automation'),
};

export function WaitForm({ config, onChange }: StepFormProps<WaitConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);
  const markInvalid = useMarkInvalid();

  return (
    <FieldGroup>
      <form.Field name="amount">
        {(amount) => (
          <ConfigField
            label={__('Wait for', 'yay-automation')}
            htmlFor="block-wait-amount"
            description={__('Then the workflow continues with the next step.', 'yay-automation')}
            errors={amount.state.meta.errors}
          >
            <div className="flex gap-2">
              <Input
                id="block-wait-amount"
                inputMode="numeric"
                className="w-28"
                value={amount.state.value}
                onChange={(event) => amount.handleChange(event.target.value)}
                onBlur={amount.handleBlur}
                aria-invalid={markInvalid(amount.state.meta)}
              />
              <form.Field name="unit">
                {(unit) => (
                  <Select value={unit.state.value} onValueChange={(value) => unit.handleChange(value as WaitUnit)}>
                    <SelectTrigger className="flex-1" aria-label={__('Unit', 'yay-automation')}>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {Object.entries(unitLabels).map(([value, label]) => (
                        <SelectItem key={value} value={value}>
                          {label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                )}
              </form.Field>
            </div>
          </ConfigField>
        )}
      </form.Field>
    </FieldGroup>
  );
}
