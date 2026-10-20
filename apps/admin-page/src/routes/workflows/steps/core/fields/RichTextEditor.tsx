import { useEffect, useLayoutEffect, useRef, useState } from 'react';

import { cn } from '@/lib/utils';
import { editorId, mountClassicEditor, type ClassicEditor } from './classicEditor';
import { ConfigField } from './ConfigField';
import { InsertVariableLink } from './InsertVariable';
import { useTagIssue, type FieldErrors } from './widgets';

/**
 * Labelled WordPress editor (Visual / Code tabs) for an `html` field. `variables`: "Insert variable"
 * puts a merge tag at the cursor in either tab. `onChange` only fires on an edit, so opening and
 * closing the form leaves the value as stored. The content inside the editor stays light in dark
 * mode (it's an iframe with WordPress's own styles).
 */
export function RichTextEditor({
  id,
  label,
  help,
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
  variables?: boolean;
  value: string;
  onChange: (value: string) => void;
  onBlur: () => void;
  invalid: boolean;
  errors: FieldErrors;
}) {
  const host = useRef<HTMLDivElement>(null);
  const editor = useRef<ClassicEditor | null>(null);
  // Kept across remounts: the editor under it is removed before the next one starts.
  const [textareaId] = useState(() => editorId(id));
  const { tagIssue, setLeft } = useTagIssue(variables, value);

  const callbacks = useRef({ onChange, onBlur });
  useLayoutEffect(() => {
    callbacks.current = { onChange, onBlur };
  });

  // What the editor holds; a value set from outside (not typed in it) loads it again.
  const held = useRef(value);
  const [loads, setLoads] = useState(0);
  useEffect(() => {
    if (value === held.current) return;
    held.current = value;
    setLoads((n) => n + 1);
  }, [value]);

  useEffect(() => {
    if (!host.current) return;
    const instance = mountClassicEditor(host.current, {
      id: textareaId,
      value: held.current,
      onEdit: (html) => {
        held.current = html;
        callbacks.current.onChange(html);
      },
      onFocus: () => setLeft(false),
      onBlur: () => {
        setLeft(true);
        callbacks.current.onBlur();
      },
    });
    editor.current = instance;
    return () => {
      instance.destroy();
      editor.current = null;
    };
  }, [textareaId, loads, setLeft]);

  return (
    <ConfigField
      label={label}
      htmlFor={textareaId}
      description={help}
      action={
        variables ? (
          <InsertVariableLink
            onInsert={(tag) => editor.current?.insert(tag)}
            focusAfter={() => {
              editor.current?.focus();
              return !!editor.current;
            }}
          />
        ) : undefined
      }
      errors={errors}
      inlineError={tagIssue}
    >
      <div
        ref={host}
        className={cn('yaymatik-rich-editor min-w-0 rounded-sm', (invalid || tagIssue) && 'ring-destructive ring-1')}
      />
    </ConfigField>
  );
}
