import { useEffect, useRef, type RefObject } from 'react';
import { Braces } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { variableTag } from '../data';
import { VariablePicker } from './VariablePicker';

type TextControl = HTMLInputElement | HTMLTextAreaElement;

type InsertVariableProps = {
  inputRef: RefObject<TextControl | null>;
  value: string;
  onChange: (value: string) => void;
};

/** `InsertVariable` on its own row under the field, for the forms not yet on `StepForm`. */
export function AddVariable(props: InsertVariableProps) {
  return (
    <div className="flex justify-end">
      <InsertVariable {...props} />
    </div>
  );
}

/**
 * "Insert variable" link for a field's label row: inserts the picked merge tag at the cursor of
 * `inputRef` (at the end when the field was never focused) and puts the cursor after it.
 */
export function InsertVariable({ inputRef, value, onChange }: InsertVariableProps) {
  // A control never focused still reports a cursor (often 0), so remember whether there's a real one.
  const focused = useRef(false);
  useEffect(() => {
    const input = inputRef.current;
    const onFocus = () => (focused.current = true);
    input?.addEventListener('focus', onFocus);
    return () => input?.removeEventListener('focus', onFocus);
  }, [inputRef]);

  const insert = (key: string) => {
    const tag = variableTag(key);
    const input = inputRef.current;
    const cursor = focused.current && input ? input : null;
    const start = cursor?.selectionStart ?? value.length;
    const end = cursor?.selectionEnd ?? value.length;
    onChange(value.slice(0, start) + tag + value.slice(end));
    // After the popover closes and returns focus, so the cursor lands after the tag.
    requestAnimationFrame(() => {
      input?.focus();
      input?.setSelectionRange(start + tag.length, start + tag.length);
    });
  };

  return (
    <VariablePicker onSelect={insert}>
      <Button type="button" variant="link" size="sm" className="h-auto gap-1 px-0 text-[12.5px]">
        <Braces className="size-3.5" aria-hidden />
        {__('Insert variable', 'yay-automation')}
      </Button>
    </VariablePicker>
  );
}
