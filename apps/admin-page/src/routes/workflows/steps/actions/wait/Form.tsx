import { useState } from 'react';
import { useForm } from '@tanstack/react-form';
import { __ } from '@wordpress/i18n';

import { FieldGroup } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ConfigField } from '../../core/fields/ConfigField';
import { useMarkInvalid } from '../../core/fields/feedback';
import { useValidateOnMount } from '../../core/fields/useValidateOnMount';
import type { StepFormProps } from '../../core/types';
import { schema, toNumber, type WaitConfig, type WaitUnit } from './schema';

const unitLabels: Record<WaitUnit, string> = {
  minutes: __('Minutes', 'yaymatik-automation-for-woocommerce'),
  hours: __('Hours', 'yaymatik-automation-for-woocommerce'),
  days: __('Days', 'yaymatik-automation-for-woocommerce'),
  weeks: __('Weeks', 'yaymatik-automation-for-woocommerce'),
};

export function WaitForm({ config, onChange }: StepFormProps<WaitConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);
  const markInvalid = useMarkInvalid();
  // As typed, so "1." or "abc" stays in the box while the stored value is a number or null.
  const [text, setText] = useState(config.delay.value === null ? '' : String(config.delay.value));

  return (
    <FieldGroup>
      <form.Field name="delay">
        {(delay) => (
          <ConfigField
            label={__('Wait for', 'yaymatik-automation-for-woocommerce')}
            htmlFor="step-wait-delay"
            description={__('Then the workflow continues with the next step.', 'yaymatik-automation-for-woocommerce')}
            errors={delay.state.meta.errors}
          >
            <div className="flex gap-2">
              <Input
                id="step-wait-delay"
                inputMode="numeric"
                className="w-28"
                value={text}
                onChange={(event) => {
                  setText(event.target.value);
                  delay.handleChange({ ...delay.state.value, value: toNumber(event.target.value) });
                }}
                onBlur={delay.handleBlur}
                aria-invalid={markInvalid(delay.state.meta)}
              />
              <Select
                value={delay.state.value.unit}
                onValueChange={(unit) => delay.handleChange({ ...delay.state.value, unit: unit as WaitUnit })}
              >
                <SelectTrigger className="flex-1" aria-label={__('Unit', 'yaymatik-automation-for-woocommerce')}>
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
            </div>
          </ConfigField>
        )}
      </form.Field>
    </FieldGroup>
  );
}
