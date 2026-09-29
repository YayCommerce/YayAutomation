import { useEffect } from 'react';

/**
 * Runs the form's change validators once on mount, so an incomplete step shows what's missing as
 * soon as its panel opens instead of after the first edit.
 */
export function useValidateOnMount(form: { validate: (cause: 'change') => unknown }) {
  useEffect(() => {
    form.validate('change');
  }, [form]);
}
