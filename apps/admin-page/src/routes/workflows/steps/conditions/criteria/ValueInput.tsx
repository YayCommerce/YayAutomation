import { forwardRef, type ComponentProps } from 'react';
import { Code, Replace, Trash2 } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { fieldByKey, type DataField } from '../../core/data';
import { VariablePicker } from '../../core/fields/VariablePicker';
import { comparableWith, VARIABLE_VALUE_TYPES, type Rule } from './schema';

/** "Add a variable" / "Add criteria": tint with the primary color on hover, like the Create workflow pills. */
export const softButton = 'hover:bg-primary/10 hover:text-primary';

/** For the picker's "Types: …" line. */
const typeLabels: Partial<Record<DataField['type'], string>> = {
  number: __('Number', 'yay-automation'),
  string: __('Text', 'yay-automation'),
  date: __('Date', 'yay-automation'),
  enum: __('Option', 'yay-automation'),
};

/** A chosen variable: its label, and its key underlined. Clicking it re-opens the picker. */
// forwardRef: VariablePicker's Slot hands its child a ref.
export const VariableLabel = forwardRef<HTMLButtonElement, { field: DataField } & ComponentProps<'button'>>(
  ({ field, ...props }, ref) => (
    <button ref={ref} type="button" className="flex min-w-0 cursor-pointer flex-col text-left text-sm" {...props}>
      <span className="font-medium">{field.label}</span>
      <span className="text-muted-foreground truncate underline decoration-dotted underline-offset-4">{field.key}</span>
    </button>
  ),
);

/**
 * The value to compare with: options for enums, a date field for dates, text otherwise. Number, text
 * and date values may instead be another variable of the same type (`</>`), shown like the variable
 * above with ⇄ and delete (back to a typed value).
 */
export function ValueInput({
  id,
  invalid,
  field,
  rule,
  onChange,
}: {
  id: string;
  invalid: boolean;
  field: DataField | undefined;
  rule: Rule;
  onChange: (rule: Rule) => void;
}) {
  const setValue = (value: string) => onChange({ ...rule, value, valueType: 'value' });
  const setVariable = (key: string) => onChange({ ...rule, value: key, valueType: 'variable' });

  if (field?.type === 'enum' && field.options?.length) {
    return (
      <Select value={rule.value} onValueChange={setValue}>
        <SelectTrigger id={id} className="w-full" aria-invalid={invalid}>
          <SelectValue placeholder={__('Choose a value', 'yay-automation')} />
        </SelectTrigger>
        <SelectContent>
          {field.options.map((option) => (
            <SelectItem key={option.value} value={option.value}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
    );
  }

  const variables =
    field && VARIABLE_VALUE_TYPES.includes(field.type)
      ? { filter: comparableWith(field), types: typeLabels[field.type] }
      : null;

  if (variables && rule.valueType === 'variable') {
    const other = fieldByKey(rule.value);
    return (
      <div className="flex items-start justify-between gap-2">
        <VariablePicker {...variables} onSelect={setVariable}>
          {other ? (
            <VariableLabel id={id} field={other} />
          ) : (
            <Button id={id} variant="secondary" size="sm" className={softButton}>
              {__('Add a variable', 'yay-automation')}
            </Button>
          )}
        </VariablePicker>
        <div className="flex shrink-0 items-center">
          <VariablePicker {...variables} onSelect={setVariable}>
            <Button variant="ghost" size="icon-sm" aria-label={__('Change variable', 'yay-automation')}>
              <Replace />
            </Button>
          </VariablePicker>
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label={__('Use a typed value', 'yay-automation')}
            onClick={() => setValue('')}
          >
            <Trash2 />
          </Button>
        </div>
      </div>
    );
  }

  return (
    <InputGroup>
      <InputGroupInput
        id={id}
        aria-invalid={invalid}
        type={field?.type === 'number' ? 'number' : field?.type === 'date' ? 'date' : 'text'}
        step={field?.type === 'number' ? 'any' : undefined}
        value={rule.value}
        disabled={!field}
        onChange={(event) => setValue(event.target.value)}
      />
      {variables && (
        <InputGroupAddon align="inline-end">
          <VariablePicker {...variables} onSelect={setVariable}>
            <InputGroupButton size="icon-md" aria-label={__('Use a variable', 'yay-automation')}>
              <Code />
            </InputGroupButton>
          </VariablePicker>
        </InputGroupAddon>
      )}
    </InputGroup>
  );
}
