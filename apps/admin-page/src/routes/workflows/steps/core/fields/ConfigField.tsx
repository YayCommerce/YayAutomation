import type { ReactNode } from 'react';
import { CircleAlert } from 'lucide-react';

import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { useShowErrors } from './feedback';
import { spotlightTarget } from './VariablePicker';

/**
 * Label row (label, plus an `action` like "Insert variable" on the right), control, first error
 * with an icon, then help text. `errors` show only while the editor shows them (see `Feedback`);
 * `inlineError` (a broken merge tag) shows whenever it's set. Red outlines come from the control's
 * own `aria-invalid`.
 */
export function ConfigField({
  label,
  htmlFor,
  description,
  action,
  errors,
  inlineError,
  children,
}: {
  label: string;
  htmlFor: string;
  description?: ReactNode;
  action?: ReactNode;
  errors: ({ message?: string } | undefined)[];
  inlineError?: string | null;
  children: ReactNode;
}) {
  const showErrors = useShowErrors();
  const error = (showErrors ? errors.find((e) => e?.message)?.message : undefined) ?? inlineError ?? undefined;

  return (
    <Field data-invalid={!!error} data-spotlight className={spotlightTarget}>
      <div className="flex items-center justify-between gap-2">
        <FieldLabel htmlFor={htmlFor}>{label}</FieldLabel>
        {action}
      </div>
      {children}
      {error && (
        <FieldError className="flex items-center gap-1.5">
          <CircleAlert className="size-3.5 shrink-0" aria-hidden />
          {error}
        </FieldError>
      )}
      {description && <FieldDescription>{description}</FieldDescription>}
    </Field>
  );
}
