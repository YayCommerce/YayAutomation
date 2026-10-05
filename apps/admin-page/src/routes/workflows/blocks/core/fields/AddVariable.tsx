import { useEffect, useRef, type RefObject } from 'react';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { variableTag } from '../data';
import { VariablePicker } from './VariablePicker';

type TextControl = HTMLInputElement | HTMLTextAreaElement;

/**
 * "Add variable" link under a text field: inserts the picked merge tag at the cursor of `inputRef`
 * (at the end when the field was never focused) and puts the cursor after it.
 */
export function AddVariable({
  available,
  inputRef,
  value,
  onChange,
}: {
  available: string[];
  inputRef: RefObject<TextControl | null>;
  value: string;
  onChange: (value: string) => void;
}) {
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
    <div className="flex justify-end">
      <VariablePicker available={available} onSelect={insert}>
        <Button type="button" variant="link" size="sm" className="h-auto px-0">
          {__('Add variable', 'yay-automation')}
        </Button>
      </VariablePicker>
    </div>
  );
}
