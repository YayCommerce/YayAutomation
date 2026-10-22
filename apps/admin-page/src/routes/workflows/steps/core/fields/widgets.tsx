import { useLayoutEffect, useRef, useState } from 'react';

import { Field, FieldContent, FieldDescription, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { tagError } from '../data';
import { ConfigField } from './ConfigField';
import { InsertVariable } from './InsertVariable';

/** Field widgets shared by `StepForm` and custom step forms. */

export type FieldErrors = ({ message?: string } | undefined)[];

/**
 * A broken merge tag in `value`, once the field was left (`setLeft(true)` on blur, `false` on
 * focus): while typing, `{{ ord` is a tag being written, not a mistake.
 */
export function useTagIssue(variables: boolean, value: string) {
  const [left, setLeft] = useState(false);
  const tagIssue = variables && left ? tagError([value]) : null;
  return { tagIssue, setLeft };
}

/**
 * Labelled text input or textarea. `variables`: "Insert variable" on the label row puts a merge tag
 * at the cursor, and a broken tag shows under the field once it was left, even while the form's own
 * errors are quiet. Only a step still coming soon may use fields PHP doesn't have yet.
 */
export function TextField({
  id,
  label,
  help,
  placeholder,
  inputMode,
  multiline = false,
  variables = false,
  value,
  onChange,
  onBlur,
  invalid,
  errors,
}: {
  id: string;
  label: string;
  help?: string;
  placeholder?: string;
  inputMode?: React.HTMLAttributes<HTMLInputElement>['inputMode'];
  multiline?: boolean;
  variables?: boolean;
  value: string;
  onChange: (value: string) => void;
  onBlur: () => void;
  invalid: boolean;
  errors: FieldErrors;
}) {
  const ref = useRef<HTMLInputElement & HTMLTextAreaElement>(null);
  const { tagIssue, setLeft } = useTagIssue(variables, value);
  useLayoutEffect(() => {
    if (multiline && ref.current) fitHeight(ref.current);
  }, [multiline, value]);
  // On mount and when the width changes (the panel opening or resizing): text wraps differently.
  useLayoutEffect(() => {
    const textarea = ref.current;
    if (!multiline || !textarea) return;
    let width = -1;
    const observer = new ResizeObserver(([entry]) => {
      if (entry.contentRect.width === width) return;
      width = entry.contentRect.width;
      fitHeight(textarea);
    });
    observer.observe(textarea);
    return () => observer.disconnect();
  }, [multiline]);
  const control = {
    ref,
    id,
    value,
    placeholder,
    inputMode,
    onChange: (event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => onChange(event.target.value),
    onFocus: () => setLeft(false),
    onBlur: () => {
      setLeft(true);
      onBlur();
    },
    'aria-invalid': invalid || !!tagIssue,
  };
  return (
    <ConfigField
      label={label}
      htmlFor={id}
      description={help}
      action={variables ? <InsertVariable inputRef={ref} value={value} onChange={onChange} /> : undefined}
      errors={errors}
      inlineError={tagIssue}
    >
      {multiline ? <Textarea rows={5} {...control} /> : <Input {...control} />}
    </ConfigField>
  );
}

const MAX_LINES = 16;

/**
 * Grows a textarea with its text, from its `rows` up to `MAX_LINES` lines, then it scrolls. In JS
 * because CSS `field-sizing` isn't in every browser yet.
 */
function fitHeight(textarea: HTMLTextAreaElement) {
  const style = getComputedStyle(textarea);
  const px = (name: string) => parseFloat(style.getPropertyValue(name)) || 0;
  // scrollHeight has the padding but not the border.
  const borders = px('border-top-width') + px('border-bottom-width');
  const max = (px('line-height') || 20) * MAX_LINES + px('padding-top') + px('padding-bottom') + borders;
  textarea.style.height = 'auto';
  const content = textarea.scrollHeight + borders;
  textarea.style.height = `${Math.min(content, max)}px`;
  textarea.style.overflowY = content > max ? 'auto' : 'hidden';
}

/** On / off, label and help on the left. */
export function ToggleField({
  id,
  label,
  help,
  checked,
  onChange,
}: {
  id: string;
  label: string;
  help?: string;
  checked: boolean;
  onChange: (checked: boolean) => void;
}) {
  return (
    <Field orientation="horizontal">
      <FieldContent>
        <FieldLabel htmlFor={id}>{label}</FieldLabel>
        {help && <FieldDescription>{help}</FieldDescription>}
      </FieldContent>
      <Switch id={id} size="sm" checked={checked} onCheckedChange={onChange} />
    </Field>
  );
}

/** Read-only line, e.g. who an email is sent from: the label muted, then the text. */
export function Notice({ label, text }: { label: string; text: string }) {
  return (
    <p title={text} className="bg-muted min-w-0 truncate rounded-lg px-3 py-2.5 text-[13px]">
      <span className="text-muted-foreground">{label}</span>
      {'  '}
      {text}
    </p>
  );
}
