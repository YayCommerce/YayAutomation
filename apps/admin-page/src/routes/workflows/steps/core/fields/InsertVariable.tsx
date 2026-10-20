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

  // Where the cursor goes once the picker has closed: after the inserted tag.
  const cursorAfter = useRef(0);
  const insert = (tag: string) => {
    const input = inputRef.current;
    const cursor = focused.current && input ? input : null;
    const start = cursor?.selectionStart ?? value.length;
    const end = cursor?.selectionEnd ?? value.length;
    onChange(value.slice(0, start) + tag + value.slice(end));
    cursorAfter.current = start + tag.length;
  };
  const focusInput = () => {
    const input = inputRef.current;
    if (!input) return false;
    input.focus();
    input.setSelectionRange(cursorAfter.current, cursorAfter.current);
    return true;
  };

  return <InsertVariableLink onInsert={insert} focusAfter={focusInput} />;
}

/** The "Insert variable" link and its picker, for a control that inserts the tag itself. */
export function InsertVariableLink({
  onInsert,
  focusAfter,
}: {
  onInsert: (tag: string) => void;
  focusAfter: () => boolean;
}) {
  return (
    <VariablePicker onSelect={(key) => onInsert(variableTag(key))} focusAfterPick={focusAfter}>
      <Button type="button" variant="link" size="sm" className="h-auto gap-1 px-0 text-[12.5px]">
        <Braces className="size-3.5" aria-hidden />
        {__('Insert variable', 'yaymatik')}
      </Button>
    </VariablePicker>
  );
}
