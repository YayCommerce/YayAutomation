import { forwardRef, type ComponentProps } from 'react';
import { criteriaFeatures } from '@edition/criteria';
import { ChevronDown, Code, X } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { focusVariants } from '@/components/ui/variants/focus.variants';
import { inputVariants } from '@/components/ui/variants/input.variants';
import { fieldByKey, isRelative, itemLabel, itemOf, takesList, type DataField } from '../../core/data';
import { KeySuggestInput } from '../../core/fields/KeySuggestInput';
import { MultiSelect } from '../../core/fields/MultiSelect';
import { SearchSelect } from '../../core/fields/SearchSelect';
import { VariablePicker } from '../../core/fields/VariablePicker';
import { useVariableScope } from '../../core/fields/variableScope';
import { comparableWith, VARIABLE_VALUE_TYPES, type Rule, type RuleUnit } from './schema';

/** For the picker's "Types: …" line. */
const typeLabels: Partial<Record<DataField['type'], string>> = {
  number: __('Number', 'yaymatik-automation-for-woocommerce'),
  string: __('Text', 'yaymatik-automation-for-woocommerce'),
  date: __('Date', 'yaymatik-automation-for-woocommerce'),
  enum: __('Option', 'yaymatik-automation-for-woocommerce'),
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
 * The value to compare with, by comparison and field type; number, text and date values may instead be another field (`</>`, × goes back).
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
  const trigger = useVariableScope().trigger?.id;
  const setValue = (value: string) => onChange({ ...rule, value, valueType: 'value' });
  const setVariable = (key: string) => onChange({ ...rule, value: key, valueType: 'variable' });
  const text = typeof rule.value === 'string' ? rule.value : '';
  const list = Array.isArray(rule.value) ? rule.value : [];

  if (takesList(rule.operator)) {
    const setList = (value: string[]) => onChange({ ...rule, value, valueType: 'value' });
    return field?.search ? (
      // Keyed: a rule moved to another search list starts with an empty search.
      <SearchSelect key={field.search} id={id} type={field.search} value={list} onChange={setList} invalid={invalid} />
    ) : (
      <MultiSelect
        id={id}
        options={field?.options ?? []}
        value={list}
        onChange={setList}
        placeholder={__('Choose values', 'yaymatik-automation-for-woocommerce')}
        invalid={invalid}
      />
    );
  }

  if (isRelative(rule.operator)) {
    return (
      <div className="grid grid-cols-2 gap-2">
        <Input
          id={id}
          type="number"
          min={1}
          step={1}
          aria-invalid={invalid}
          value={text}
          onChange={(event) => setValue(event.target.value)}
        />
        <Select value={rule.unit ?? 'days'} onValueChange={(unit) => onChange({ ...rule, unit: unit as RuleUnit })}>
          <SelectTrigger className="w-full" aria-label={__('Unit', 'yaymatik-automation-for-woocommerce')}>
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="hours">{__('hours', 'yaymatik-automation-for-woocommerce')}</SelectItem>
            <SelectItem value="days">{__('days', 'yaymatik-automation-for-woocommerce')}</SelectItem>
          </SelectContent>
        </Select>
      </div>
    );
  }

  if (field?.type === 'enum' && field.options?.length) {
    return (
      <Select value={text} onValueChange={setValue}>
        <SelectTrigger id={id} className="w-full" aria-invalid={invalid}>
          <SelectValue placeholder={__('Choose a value', 'yaymatik-automation-for-woocommerce')} />
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
    criteriaFeatures.variables && field && VARIABLE_VALUE_TYPES.includes(field.type)
      ? { filter: comparableWith(field), types: typeLabels[field.type] }
      : null;

  if (variables && rule.valueType === 'variable') {
    return (
      <div className="flex min-w-0 items-center gap-1">
        <VariablePicker {...variables} onSelect={setVariable}>
          <DataSelect
            id={id}
            field={fieldByKey(text, trigger)}
            placeholder={__('Choose data', 'yaymatik-automation-for-woocommerce')}
            invalid={invalid}
          />
        </VariablePicker>
        <Button
          variant="ghost"
          size="icon-sm"
          className="shrink-0"
          aria-label={__('Type a value instead', 'yaymatik-automation-for-woocommerce')}
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
        value={text}
        disabled={!field}
        onChange={(event) => setValue(event.target.value)}
      />
      {variables && (
        <InputGroupAddon align="inline-end">
          <VariablePicker {...variables} onSelect={setVariable}>
            <InputGroupButton
              size="icon-md"
              aria-label={__('Compare with other data', 'yaymatik-automation-for-woocommerce')}
            >
              <Code />
            </InputGroupButton>
          </VariablePicker>
        </InputGroupAddon>
      )}
    </InputGroup>
  );
}

/** A custom field's name. Customer fields suggest the names other workflows use; any other can be typed. */
export function MetaKeyInput({
  id,
  invalid,
  field,
  rule,
  onChange,
}: {
  id: string;
  invalid: boolean;
  field: DataField;
  rule: Rule;
  onChange: (rule: Rule) => void;
}) {
  const control = {
    id,
    'aria-invalid': invalid,
    'aria-label': __('Field name', 'yaymatik-automation-for-woocommerce'),
    placeholder: __('Field name', 'yaymatik-automation-for-woocommerce'),
    className: 'font-mono text-xs',
    value: rule.key ?? '',
  };
  return field.key === 'customer.meta' ? (
    <KeySuggestInput {...control} onChange={(key) => onChange({ ...rule, key })} />
  ) : (
    <Input {...control} onChange={(event) => onChange({ ...rule, key: event.target.value })} />
  );
}
