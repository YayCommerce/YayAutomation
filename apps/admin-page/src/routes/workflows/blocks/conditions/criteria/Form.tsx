import { useState } from 'react';
import { ChevronDown, CircleAlert, Replace, Trash2 } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { fieldByKey, needsValue, operatorLabels, operatorsFor } from '../../core/data';
import { feedbackStyles, useFeedback, type Feedback } from '../../core/fields/feedback';
import { spotlightTarget, VariablePicker } from '../../core/fields/VariablePicker';
import type { BlockFormProps } from '../../core/types';
import { comparableWith, criteriaErrors, criteriaRequired, emptyRule, type CriteriaConfig, type Rule } from './schema';
import { softButton, ValueInput, VariableLabel } from './ValueInput';

const joinerLabel = { all: __('AND', 'yay-automation'), any: __('OR', 'yay-automation') };

/**
 * "IF" + one box per criteria (variable, comparison, value). With one criteria, "Add criteria"
 * asks AND or OR; after that a select between the boxes switches the whole group. A box added that
 * way opens "Add a variable" at once and goes away if nothing is picked. The condition always keeps
 * one box: deleting the last one empties it instead.
 */
export function CriteriaForm({ config, onChange }: BlockFormProps<CriteriaConfig>) {
  const [value, setValue] = useState(config);
  // The box just added by "Add criteria", until its variable is picked.
  const [pending, setPending] = useState<number | null>(null);
  const update = (next: CriteriaConfig) => {
    setValue(next);
    onChange(next);
  };
  const setRule = (index: number, rule: Rule) =>
    update({ ...value, rules: value.rules.map((r, i) => (i === index ? rule : r)) });
  const removeRule = (index: number) =>
    update({
      ...value,
      rules: value.rules.length === 1 ? [emptyRule()] : value.rules.filter((_, i) => i !== index),
    });
  const addRule = (match = value.match) => {
    setPending(value.rules.length);
    update({ match, rules: [...value.rules, emptyRule()] });
  };

  const feedback = useFeedback();
  const errors = criteriaErrors(value.rules);
  // Review stays quiet about a condition with no variable yet; error mode says it's required.
  const errorFor = (index: number) =>
    feedback === 'none' || (feedback === 'review' && errors[index] === criteriaRequired) ? null : errors[index];

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
            error={errorFor(index)}
            feedback={feedback}
            autoOpen={index === pending}
            onChange={(next) => {
              setRule(index, next);
              if (index === pending) setPending(null);
            }}
            onDismissNew={() => {
              setPending(null);
              update({ ...value, rules: value.rules.filter((_, i) => i !== index) });
            }}
            onRemove={() => removeRule(index)}
          />
        </div>
      ))}

      <div className="border-t pt-3">
        {value.rules.length === 1 ? (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="secondary" size="sm" className={softButton}>
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
          <Button variant="secondary" size="sm" className={softButton} onClick={() => addRule()}>
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
  error,
  feedback,
  autoOpen,
  onChange,
  onDismissNew,
  onRemove,
}: {
  id: string;
  rule: Rule;
  /** Shown under the box, in the feedback mode's color. */
  error: string | null;
  feedback: Feedback;
  /** Just added by "Add criteria": open the variable picker right away. */
  autoOpen: boolean;
  onChange: (rule: Rule) => void;
  /** The picker of a just-added box closed without a pick. */
  onDismissNew: () => void;
  onRemove: () => void;
}) {
  const field = fieldByKey(rule.field);
  const tone = error && feedback !== 'none' ? feedbackStyles[feedback] : null;

  // Another variable of the same type keeps the comparison (and the value, if it still fits);
  // otherwise the new type's first comparison is picked and the value cleared.
  const chooseVariable = (key: string) => {
    const next = fieldByKey(key)!;
    const keep = next.type === field?.type;
    const other = fieldByKey(rule.value);
    const fits =
      keep &&
      (rule.valueType === 'variable'
        ? !!other && comparableWith(next)(other)
        : !next.options?.length || next.options.some((o) => o.value === rule.value));
    onChange({
      field: key,
      operator: keep ? rule.operator : operatorsFor(next.type)[0],
      value: fits ? rule.value : '',
      valueType: fits ? rule.valueType : 'value',
    });
  };

  return (
    <div className="flex flex-col gap-1.5">
      <div
        data-spotlight
        className={cn('flex flex-col gap-3 rounded-lg border p-3', tone ? tone.tint : 'bg-muted/50', spotlightTarget)}
      >
        <div className="flex items-start justify-between gap-2">
          <VariablePicker
            conditionalOnly
            autoOpen={autoOpen}
            onSelect={chooseVariable}
            onDismiss={autoOpen ? onDismissNew : undefined}
          >
            {field ? (
              <VariableLabel field={field} />
            ) : (
              <Button variant="secondary" size="sm" className={softButton}>
                {__('Add a variable', 'yay-automation')}
              </Button>
            )}
          </VariablePicker>
          <div className="flex shrink-0 items-center">
            {field && (
              <VariablePicker conditionalOnly onSelect={chooseVariable}>
                <Button variant="ghost" size="icon-sm" aria-label={__('Change variable', 'yay-automation')}>
                  <Replace />
                </Button>
              </VariablePicker>
            )}
            <Button
              variant="ghost"
              size="icon-sm"
              aria-label={__('Remove criteria', 'yay-automation')}
              onClick={onRemove}
            >
              <Trash2 />
            </Button>
          </div>
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
          <Field data-spotlight className={spotlightTarget}>
            <FieldLabel htmlFor={`${id}-value`}>{field?.label ?? __('Value', 'yay-automation')}</FieldLabel>
            <ValueInput
              id={`${id}-value`}
              invalid={feedback === 'error' && !!error}
              field={field}
              rule={rule}
              onChange={onChange}
            />
          </Field>
        )}
      </div>
      {error && tone && <ErrorLine className={tone.text}>{error}</ErrorLine>}
    </div>
  );
}

function ErrorLine({ className, children }: { className?: string; children: string }) {
  return (
    <FieldError className={cn('flex items-center gap-1.5', className)}>
      <CircleAlert className="size-3.5 shrink-0" aria-hidden />
      {children}
    </FieldError>
  );
}
