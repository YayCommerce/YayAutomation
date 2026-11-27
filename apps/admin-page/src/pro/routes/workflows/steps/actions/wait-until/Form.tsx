import { useRef } from 'react';
import { useForm } from '@tanstack/react-form';
import { Clock } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { FieldGroup } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group';
import { ConfigField } from '@/routes/workflows/steps/core/fields/ConfigField';
import { useMarkInvalid } from '@/routes/workflows/steps/core/fields/feedback';
import { MultiSelect } from '@/routes/workflows/steps/core/fields/MultiSelect';
import { useValidateOnMount } from '@/routes/workflows/steps/core/fields/useValidateOnMount';
import type { StepFormProps } from '@/routes/workflows/steps/core/types';
import { schema, type WaitUntilConfig } from './schema';

const field = (key: string) => window.yayMatikMeta.automation.steps.wait_until?.fields.find((f) => f.key === key);

/** Labels and help come from includes/Pro/Workflow/Actions/WaitUntil.php `fields()`; the time is a native time input with the kit's clock button. */
export function WaitUntilForm({ config, onChange }: StepFormProps<WaitUntilConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);
  const markInvalid = useMarkInvalid();
  const timeInput = useRef<HTMLInputElement>(null);
  const openPicker = () => {
    const input = timeInput.current;
    input?.focus();
    // showPicker() is missing in older browsers and throws where the browser refuses it: the focused input still takes typing.
    try {
      input?.showPicker?.();
    } catch {
      return;
    }
  };
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
            {/* A wrapper, so the Field's child width rule sizes it, not the 160px control. */}
            <div>
              <InputGroup className="w-40">
                <InputGroupInput
                  ref={timeInput}
                  id="step-field-time"
                  type="time"
                  step={60}
                  className="min-w-0 flex-1 [&::-webkit-calendar-picker-indicator]:hidden"
                  value={time.state.value}
                  onChange={(event) => time.handleChange(event.target.value)}
                  onBlur={time.handleBlur}
                  aria-invalid={markInvalid(time.state.meta)}
                />
                <InputGroupAddon align="inline-end">
                  <InputGroupButton
                    size="icon-md"
                    className="text-muted-foreground"
                    aria-label={__('Choose a time', 'yaymatik-automation-for-woocommerce')}
                    onClick={openPicker}
                  >
                    <Clock aria-hidden />
                  </InputGroupButton>
                </InputGroupAddon>
              </InputGroup>
            </div>
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
