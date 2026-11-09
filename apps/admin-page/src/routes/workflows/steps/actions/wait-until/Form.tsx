import { useForm } from '@tanstack/react-form';

import { FieldGroup } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { ConfigField } from '../../core/fields/ConfigField';
import { useMarkInvalid } from '../../core/fields/feedback';
import { MultiSelect } from '../../core/fields/MultiSelect';
import { useValidateOnMount } from '../../core/fields/useValidateOnMount';
import type { StepFormProps } from '../../core/types';
import { schema, type WaitUntilConfig } from './schema';

const field = (key: string) => window.yayMatikMeta.automation.steps.wait_until?.fields.find((f) => f.key === key);

/** Labels and help come from includes/Workflow/Actions/WaitUntil.php `fields()`; the time is a native time input. */
export function WaitUntilForm({ config, onChange }: StepFormProps<WaitUntilConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);
  const markInvalid = useMarkInvalid();
  const timeField = field('time');
  const daysField = field('weekdays');

  return (
    <FieldGroup>
      <form.Field name="time">
        {(time) => (
          <ConfigField
            label={timeField?.label ?? ''}
            htmlFor="step-field-time"
            description={timeField?.help}
            errors={time.state.meta.errors}
          >
            <Input
              id="step-field-time"
              type="time"
              step={60}
              className="w-40"
              value={time.state.value}
              onChange={(event) => time.handleChange(event.target.value)}
              onBlur={time.handleBlur}
              aria-invalid={markInvalid(time.state.meta)}
            />
          </ConfigField>
        )}
      </form.Field>
      <form.Field name="weekdays">
        {(days) => (
          <ConfigField
            label={daysField?.label ?? ''}
            htmlFor="step-field-weekdays"
            description={daysField?.help}
            errors={days.state.meta.errors}
          >
            <MultiSelect
              id="step-field-weekdays"
              options={daysField?.options ?? []}
              value={days.state.value}
              onChange={days.handleChange}
              placeholder={daysField?.placeholder ?? ''}
              invalid={markInvalid(days.state.meta)}
            />
          </ConfigField>
        )}
      </form.Field>
    </FieldGroup>
  );
}
