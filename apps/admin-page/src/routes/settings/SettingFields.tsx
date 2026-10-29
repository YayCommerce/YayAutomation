import type { ReactNode } from 'react';
import { useStore } from '@tanstack/react-form';
import type { Settings } from '@yaymatik/types';

import { Field, FieldContent, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group';
import { Switch } from '@/components/ui/switch';
import type { SettingsForm } from './settingsForm';

/** What `PUT /settings` refused, by field, until that field is edited. */
export type ServerErrors = Partial<Record<keyof Settings, string>>;

/** `aria-describedby` for a setting's control: its help, then its error when shown. */
export const describedBy = (id: string, error?: string) => (error ? `${id}-help ${id}-error` : `${id}-help`);

/** Label, help and error of one setting. */
export function SettingText({
  id,
  label,
  description,
  error,
}: {
  id: string;
  label: string;
  description: ReactNode;
  error?: string;
}) {
  return (
    <FieldContent>
      <FieldLabel htmlFor={id}>{label}</FieldLabel>
      <FieldDescription id={`${id}-help`}>{description}</FieldDescription>
      {error && <FieldError id={`${id}-error`}>{error}</FieldError>}
    </FieldContent>
  );
}

/** Horizontal at every width, so the switch stays on the right of its label. */
export function SwitchSetting({
  form,
  name,
  label,
  description,
  serverErrors,
}: {
  form: SettingsForm;
  name: 'cartTracking' | 'captureGuestEmail';
  label: string;
  description: string;
  serverErrors: ServerErrors;
}) {
  const id = `yaymatik-setting-${name}`;
  const error = serverErrors[name];
  return (
    <form.Field name={name}>
      {(field) => (
        <Field orientation="horizontal" data-invalid={!!error}>
          <SettingText id={id} label={label} description={description} error={error} />
          <Switch
            id={id}
            size="sm"
            checked={field.state.value}
            onCheckedChange={field.handleChange}
            aria-describedby={describedBy(id, error)}
            aria-invalid={!!error}
          />
        </Field>
      )}
    </form.Field>
  );
}

/** Plain number input: blank is null, and nothing is clamped, so an out-of-range value shows its error. */
export function NumberSetting({
  form,
  name,
  label,
  description,
  unit,
  min,
  max,
  serverErrors,
}: {
  form: SettingsForm;
  name: 'abandonMinutes' | 'cartRetentionDays' | 'runRetentionDays';
  label: string;
  description: string;
  unit: string;
  min: number;
  max: number;
  serverErrors: ServerErrors;
}) {
  const id = `yaymatik-setting-${name}`;
  const submitted = useStore(form.store, (state) => state.submissionAttempts > 0);
  return (
    <form.Field name={name}>
      {(field) => {
        const { errors, isBlurred } = field.state.meta;
        const clientError =
          isBlurred || submitted ? (errors[0] as { message?: string } | undefined)?.message : undefined;
        const error = clientError ?? serverErrors[name];
        return (
          <Field orientation="responsive" data-invalid={!!error}>
            <SettingText id={id} label={label} description={description} error={error} />
            {/* `!`: the responsive Field's `*:w-auto` has the same specificity and comes later in the CSS. */}
            <InputGroup className="@md/field-group:w-40! @md/field-group:flex-none">
              <InputGroupInput
                id={id}
                type="number"
                inputMode="numeric"
                min={min}
                max={max}
                step={1}
                className="min-w-0 flex-1 text-left tabular-nums"
                value={field.state.value ?? ''}
                onChange={(event) => field.handleChange(event.target.value === '' ? null : event.target.valueAsNumber)}
                onBlur={field.handleBlur}
                aria-describedby={describedBy(id, error)}
                aria-invalid={!!error}
              />
              <InputGroupAddon align="inline-end">{unit}</InputGroupAddon>
            </InputGroup>
          </Field>
        );
      }}
    </form.Field>
  );
}
