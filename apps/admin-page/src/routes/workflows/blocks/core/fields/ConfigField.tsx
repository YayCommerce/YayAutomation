import type { ReactNode } from 'react';
import { CircleAlert } from 'lucide-react';

import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';

/** Label, control, help text and error for one config field. Shows only the first error, with an icon. */
export function ConfigField({
  label,
  htmlFor,
  description,
  errors,
  children,
}: {
  label: string;
  htmlFor: string;
  description?: ReactNode;
  errors: ({ message?: string } | undefined)[];
  children: ReactNode;
}) {
  const error = errors.find((e) => e?.message)?.message;

  return (
    <Field data-invalid={!!error}>
      <FieldLabel htmlFor={htmlFor}>{label}</FieldLabel>
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
