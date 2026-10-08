import type { ReactNode } from 'react';
import { CircleAlert } from 'lucide-react';

import { cn } from '@/lib/utils';
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field';
import { feedbackStyles, useFeedback } from './feedback';
import { spotlightTarget } from './VariablePicker';

/**
 * Label, control, help text and error for one config field. Shows only the first error, with an
 * icon, in the editor's feedback mode: nothing, a blue "review" tint, or red. Red outlines come from
 * the control's own `aria-invalid`.
 */
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
  const feedback = useFeedback();
  const error = feedback === 'none' ? undefined : errors.find((e) => e?.message)?.message;
  const review = error && feedback === 'review';

  return (
    <Field
      data-invalid={error && feedback === 'error'}
      data-spotlight
      className={cn(
        spotlightTarget,
        review && '**:[[role=combobox]]:bg-primary/5 [&_input]:bg-primary/5 [&_textarea]:bg-primary/5',
      )}
    >
      <FieldLabel htmlFor={htmlFor}>{label}</FieldLabel>
      {children}
      {error && (
        <FieldError className={cn('flex items-center gap-1.5', review && feedbackStyles.review.text)}>
          <CircleAlert className="size-3.5 shrink-0" aria-hidden />
          {error}
        </FieldError>
      )}
      {description && <FieldDescription>{description}</FieldDescription>}
    </Field>
  );
}
