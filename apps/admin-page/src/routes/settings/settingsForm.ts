import { useForm } from '@tanstack/react-form';
import type { Settings } from '@yaymatik/types';
import * as v from 'valibot';
import { __, sprintf } from '@wordpress/i18n';

type NumberKey = 'abandonMinutes' | 'cartRetentionDays' | 'runRetentionDays';

/** What the form edits: a number field holds null while it's blank. */
export type SettingsValues = Omit<Settings, NumberKey> & Record<NumberKey, number | null>;

/** PHP `Settings::check()`: a whole number in `[min, max]`, with its message. */
const wholeNumber = (min: number, max: number) => {
  /* translators: 1: smallest number, 2: largest number */
  const message = sprintf(__('Enter a whole number from %1$d to %2$d.', 'yaymatik'), min, max);
  return v.pipe(
    v.nullable(v.number(message)),
    v.check((value) => value !== null && Number.isInteger(value) && value >= min && value <= max, message),
  );
};

/** Ranges match PHP `Settings::RANGES`. */
export const settingsSchema = v.object({
  cartTracking: v.boolean(),
  abandonMinutes: wholeNumber(15, 1440),
  captureGuestEmail: v.boolean(),
  cartRetentionDays: wholeNumber(7, 365),
  runRetentionDays: wholeNumber(7, 730),
  unsubscribeText: v.string(),
});

/** Drops the response's read-only keys. */
export const toValues = (settings: Settings): SettingsValues => ({
  cartTracking: settings.cartTracking,
  abandonMinutes: settings.abandonMinutes,
  captureGuestEmail: settings.captureGuestEmail,
  cartRetentionDays: settings.cartRetentionDays,
  runRetentionDays: settings.runRetentionDays,
  unsubscribeText: settings.unsubscribeText,
});

/** The keys whose value differs from what's saved: what a save sends. */
export function changedSettings(values: SettingsValues, saved: SettingsValues): Partial<Settings> {
  return Object.fromEntries(
    (Object.keys(saved) as (keyof SettingsValues)[])
      .filter((key) => values[key] !== saved[key])
      .map((key) => [key, values[key]]),
  );
}

/** Shown only while cart tracking is on. */
const TRACKING_FIELDS = ['abandonMinutes', 'captureGuestEmail'] as const;

/**
 * `onSubmit` returns the saved values to reset the form to, or null when the save failed. Turning
 * tracking off puts its hidden fields back to `saved`, so they never block or join a save.
 */
export function useSettingsForm(
  saved: SettingsValues,
  onSubmit: (values: SettingsValues) => Promise<SettingsValues | null>,
  onFieldChange: (name: keyof Settings) => void,
) {
  return useForm({
    defaultValues: saved,
    validators: { onChange: settingsSchema },
    listeners: {
      onChange: ({ fieldApi }) => {
        onFieldChange(fieldApi.name as keyof Settings);
        if (fieldApi.name !== 'cartTracking' || fieldApi.state.value) return;
        for (const name of TRACKING_FIELDS) {
          fieldApi.form.setFieldValue(name, saved[name]);
          onFieldChange(name);
        }
      },
    },
    onSubmit: async ({ value, formApi }) => {
      const next = await onSubmit(value);
      if (!next) return;
      // Edits made while the save was running stay, as unsaved changes.
      const edited = changedSettings(formApi.state.values, value);
      formApi.reset(next);
      for (const [key, changed] of Object.entries(edited)) {
        formApi.setFieldValue(key as keyof SettingsValues, changed as never);
      }
    },
  });
}

export type SettingsForm = ReturnType<typeof useSettingsForm>;
