import { useState } from 'react';
import { ChevronDown, CircleAlert, Trash2 } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { fieldByKey, needsValue, operatorLabels, operatorsFor, type DataField } from '../../core/data';
import { VariablePicker } from '../../core/fields/VariablePicker';
import type { BlockFormProps } from '../../core/types';
import { emptyRule, ruleError, type CriteriaConfig, type Rule } from './schema';

const joinerLabel = { all: __('AND', 'yay-automation'), any: __('OR', 'yay-automation') };

/**
 * "IF" + one box per criteria (variable, comparison, value). With one criteria, "Add criteria"
 * asks AND or OR; after that a select between the boxes switches the whole group.
 */
export function CriteriaForm({ config, onChange, available }: BlockFormProps<CriteriaConfig>) {
  const [value, setValue] = useState(config);
  const update = (next: CriteriaConfig) => {
    setValue(next);
    onChange(next);
  };
  const setRule = (index: number, rule: Rule) =>
    update({ ...value, rules: value.rules.map((r, i) => (i === index ? rule : r)) });
  const addRule = (match = value.match) => update({ match, rules: [...value.rules, emptyRule()] });

  return (
    <div className="flex flex-col gap-3">
      <span className="text-sm font-medium">{__('IF', 'yay-automation')}</span>

      {value.rules.map((rule, index) => (
        <div key={index} className="flex flex-col gap-3">
          {index > 0 && (
            <Select value={value.match} onValueChange={(match) => update({ ...value, match: match as 'all' | 'any' })}>
              <SelectTrigger size="sm" className="w-fit" aria-label={__('Match', 'yay-automation')}>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">{joinerLabel.all}</SelectItem>
                <SelectItem value="any">{joinerLabel.any}</SelectItem>
              </SelectContent>
            </Select>
          )}
          <RuleBox
            id={`criteria-${index}`}
            rule={rule}
            available={available}
            onChange={(next) => setRule(index, next)}
            onRemove={() => update({ ...value, rules: value.rules.filter((_, i) => i !== index) })}
          />
        </div>
      ))}

      <div className="border-t pt-3">
        {value.rules.length === 1 ? (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="secondary" size="sm">
                {__('Add criteria', 'yay-automation')}
                <ChevronDown />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
              <DropdownMenuItem onSelect={() => addRule('all')}>{joinerLabel.all}</DropdownMenuItem>
              <DropdownMenuItem onSelect={() => addRule('any')}>{joinerLabel.any}</DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        ) : (
          <Button variant="secondary" size="sm" onClick={() => addRule()}>
            {__('Add criteria', 'yay-automation')}
          </Button>
        )}
      </div>
    </div>
  );
}

function RuleBox({
  id,
  rule,
  available,
  onChange,
  onRemove,
}: {
  id: string;
  rule: Rule;
  available: string[];
  onChange: (rule: Rule) => void;
  onRemove: () => void;
}) {
  const field = fieldByKey(rule.field);
  const error = rule.field ? ruleError(rule) : null;

  return (
    <div className="bg-muted/50 flex flex-col gap-3 rounded-lg border p-3">
      <div className="flex items-start justify-between gap-2">
        {/* Choosing another variable keeps the comparison only when the new type offers it. */}
        <VariablePicker
          available={available}
          conditionalOnly
          onSelect={(key) => {
            const type = fieldByKey(key)?.type;
            const keep = type === field?.type;
            onChange({ field: key, operator: keep ? rule.operator : '', value: keep ? rule.value : '' });
          }}
        >
          {field ? (
            <button type="button" className="flex min-w-0 cursor-pointer flex-col text-left text-sm">
              <span className="font-medium">{field.label}</span>
              <span className="text-muted-foreground truncate underline decoration-dotted underline-offset-4">
                {field.key}
              </span>
            </button>
          ) : (
            <Button variant="secondary" size="sm">
              {__('Add a variable', 'yay-automation')}
            </Button>
          )}
        </VariablePicker>
        <Button variant="ghost" size="icon-sm" aria-label={__('Remove criteria', 'yay-automation')} onClick={onRemove}>
          <Trash2 />
        </Button>
      </div>

      <Select value={rule.operator} onValueChange={(operator) => onChange({ ...rule, operator })} disabled={!field}>
        <SelectTrigger id={`${id}-operator`} className="w-full" aria-label={__('Comparison', 'yay-automation')}>
          <SelectValue placeholder={__('Choose a comparison', 'yay-automation')} />
        </SelectTrigger>
        <SelectContent>
          {field &&
            operatorsFor(field.type).map((operator) => (
              <SelectItem key={operator} value={operator}>
                {operatorLabels[operator]}
              </SelectItem>
            ))}
        </SelectContent>
      </Select>

      {needsValue(rule.operator) && (
        <Field data-invalid={!!error}>
          <FieldLabel htmlFor={`${id}-value`}>{field?.label ?? __('Value', 'yay-automation')}</FieldLabel>
          <ValueInput
            id={`${id}-value`}
            field={field}
            value={rule.value}
            onChange={(v) => onChange({ ...rule, value: v })}
          />
        </Field>
      )}

      {error && (
        <FieldError className="flex items-center gap-1.5">
          <CircleAlert className="size-3.5 shrink-0" aria-hidden />
          {error}
        </FieldError>
      )}
    </div>
  );
}

/** Input matching the variable's type: options for enums, a date field for dates, text otherwise. */
function ValueInput({
  id,
  field,
  value,
  onChange,
}: {
  id: string;
  field: DataField | undefined;
  value: string;
  onChange: (value: string) => void;
}) {
  if (field?.type === 'enum' && field.options?.length) {
    return (
      <Select value={value} onValueChange={onChange}>
        <SelectTrigger id={id} className="w-full">
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

  return (
    <Input
      id={id}
      type={field?.type === 'number' ? 'number' : field?.type === 'date' ? 'date' : 'text'}
      step={field?.type === 'number' ? 'any' : undefined}
      value={value}
      disabled={!field}
      onChange={(event) => onChange(event.target.value)}
    />
  );
}
