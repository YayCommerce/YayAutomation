import { forwardRef, type ComponentProps } from 'react';
import { ChevronDown, Code, X } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { focusVariants } from '@/components/ui/variants/focus.variants';
import { inputVariants } from '@/components/ui/variants/input.variants';
import { fieldByKey, itemLabel, itemOf, type DataField } from '../../core/data';
import { VariablePicker } from '../../core/fields/VariablePicker';
import { comparableWith, VARIABLE_VALUE_TYPES, type Rule } from './schema';

/** For the picker's "Types: …" line. */
const typeLabels: Partial<Record<DataField['type'], string>> = {
  number: __('Number', 'yaymatik'),
  string: __('Text', 'yaymatik'),
  date: __('Date', 'yaymatik'),
  enum: __('Option', 'yaymatik'),
};

/** "Order total" under Order → "Total": the item already shows in small text beside it. */
function shortLabel(field: DataField) {
  const item = itemLabel(itemOf(field.key));
  if (!field.label.toLowerCase().startsWith(`${item.toLowerCase()} `)) return field.label;
  const rest = field.label.slice(item.length + 1);
  return rest.charAt(0).toUpperCase() + rest.slice(1);
}

/**
 * Looks like a select: the chosen data field with its item in small text ("Order  Total"), or a
 * placeholder. Opens the picker it's wrapped in.
 */
// forwardRef: VariablePicker's Slot hands its child a ref.
export const DataSelect = forwardRef<
  HTMLButtonElement,
  { field: DataField | undefined; placeholder: string; invalid?: boolean } & ComponentProps<'button'>
>(({ field, placeholder, invalid, className, ...props }, ref) => (
  <button
    ref={ref}
    type="button"
    aria-invalid={invalid || undefined}
    className={cn(
      'border-input dark:bg-input/30 dark:hover:bg-input/50 transition-default flex h-9 w-full min-w-0 cursor-pointer items-center justify-between gap-2 rounded-md border bg-transparent px-3 text-left text-sm',
      focusVariants(),
      inputVariants({ variant: 'picker' }),
      className,
    )}
    {...props}
  >
    {field ? (
      <span className="flex min-w-0 items-baseline gap-2">
        <small className="text-muted-foreground shrink-0 text-xs">{itemLabel(itemOf(field.key))}</small>
        <span className="truncate">{shortLabel(field)}</span>
      </span>
    ) : (
      <span className="text-muted-foreground truncate">{placeholder}</span>
    )}
    <ChevronDown className="text-muted-foreground size-4 shrink-0" />
  </button>
));

/**
 * The value to compare with: options for enums, a date field for dates, text otherwise. Number, text
 * and date values may instead be another data field of the same type (`</>`), shown like the rule's
 * data select, with × back to a typed value.
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
          <SelectValue placeholder={__('Choose a value', 'yaymatik')} />
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
    return (
      <div className="flex min-w-0 items-center gap-1">
        <VariablePicker {...variables} onSelect={setVariable}>
          <DataSelect
            id={id}
            field={fieldByKey(rule.value)}
            placeholder={__('Choose data', 'yaymatik')}
            invalid={invalid}
          />
        </VariablePicker>
        <Button
          variant="ghost"
          size="icon-sm"
          className="shrink-0"
          aria-label={__('Type a value instead', 'yaymatik')}
          onClick={() => setValue('')}
        >
          <X />
        </Button>
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
            <InputGroupButton size="icon-md" aria-label={__('Compare with other data', 'yaymatik')}>
              <Code />
            </InputGroupButton>
          </VariablePicker>
        </InputGroupAddon>
      )}
    </InputGroup>
  );
}
