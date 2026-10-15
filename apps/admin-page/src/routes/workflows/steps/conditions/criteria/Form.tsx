import { useState } from 'react';
import { Check, CircleAlert, Info, Plus, Trash2 } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { fieldByKey, needsValue, operatorLabels, operatorsFor } from '../../core/data';
import { errorStyles, useShowErrors } from '../../core/fields/feedback';
import { spotlightTarget, VariablePicker } from '../../core/fields/VariablePicker';
import type { StepFormProps } from '../../core/types';
import { comparableWith, criteriaErrors, emptyRule, type CriteriaConfig, type Rule } from './schema';
import { DataSelect, ValueInput } from './ValueInput';

const joinerText = { all: __('and', 'yaymatik'), any: __('or', 'yaymatik') };

/**
 * The condition as a sentence: "Take the Yes path when [all / any] of these match:", one box per rule
 * (data, comparison, value) joined by "and" / "or", then "Otherwise the No path runs." A rule added
 * with "Add rule" opens its data picker at once and goes away if nothing is picked. The condition
 * always keeps one rule: deleting the last one empties it instead.
 */
export function CriteriaForm({ config, onChange }: StepFormProps<CriteriaConfig>) {
  const [value, setValue] = useState(config);
  // The rule just added by "Add rule", until its data is picked.
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
  const addRule = () => {
    setPending(value.rules.length);
    update({ ...value, rules: [...value.rules, emptyRule()] });
  };

  const showErrors = useShowErrors();
  const errors = criteriaErrors(value.rules);
  const errorFor = (index: number) => (showErrors ? errors[index] : null);

  return (
    <div className="flex flex-col gap-3.5">
      <div className="flex flex-wrap items-center gap-1.5 text-sm/7.5">
        {/* translators: 1: the Yes path's tag, 2: "all" or "any" */}
        {__('Take the %1$s path when %2$s of these match:', 'yaymatik')
          .split(/(%\d\$s)/)
          .map((part, i) =>
            part === '%1$s' ? (
              <span
                key={i}
                className="inline-flex h-5.5 items-center gap-1 rounded-full border pr-2 pl-1.5 text-[12.5px] font-semibold"
              >
                <Check className="text-kind-action size-3 stroke-[2.5]" aria-hidden />
                {__('Yes', 'yaymatik')}
              </span>
            ) : part === '%2$s' ? (
              <Select
                key={i}
                value={value.match}
                onValueChange={(match) => update({ ...value, match: match as 'all' | 'any' })}
              >
                <SelectTrigger size="sm" className="h-7.5 px-2.5" aria-label={__('Match', 'yaymatik')}>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">{__('all', 'yaymatik')}</SelectItem>
                  <SelectItem value="any">{__('any', 'yaymatik')}</SelectItem>
                </SelectContent>
              </Select>
            ) : (
              part.trim() && <span key={i}>{part.trim()}</span>
            ),
          )}
      </div>

      <div className="flex flex-col gap-2">
        {value.rules.map((rule, index) => (
          <div key={index} className="flex flex-col gap-2">
            {index > 0 && (
              <span className="text-muted-foreground pl-2.5 text-[11.5px] font-semibold tracking-wider uppercase">
                {joinerText[value.match]}
              </span>
            )}
            <RuleBox
              id={`rule-${index}`}
              rule={rule}
              error={errorFor(index)}
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
      </div>

      <Button variant="ghost" size="sm" className="w-fit pl-1" onClick={addRule}>
        <Plus />
        {__('Add rule', 'yaymatik')}
      </Button>

      <p className="text-muted-foreground flex items-center gap-1.5 text-[12.5px]">
        <Info className="size-3.25 shrink-0" aria-hidden />
        {__('Otherwise the No path runs.', 'yaymatik')}
      </p>
    </div>
  );
}

function RuleBox({
  id,
  rule,
  error,
  autoOpen,
  onChange,
  onDismissNew,
  onRemove,
}: {
  id: string;
  rule: Rule;
  /** Shown under the box; null while the form shows no errors. */
  error: string | null;
  /** Just added by "Add rule": open the data picker right away. */
  autoOpen: boolean;
  onChange: (rule: Rule) => void;
  /** The picker of a just-added rule closed without a pick. */
  onDismissNew: () => void;
  onRemove: () => void;
}) {
  const field = fieldByKey(rule.field);
  const tone = error ? errorStyles : null;

  // Another field of the same type keeps the comparison (and the value, if it still fits);
  // otherwise the new type's first comparison is picked and the value cleared.
  const chooseField = (key: string) => {
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
        className={cn(
          'grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] gap-x-2 gap-y-1.5 rounded-[10px] border p-2.5',
          tone ? tone.tint : 'bg-muted/60 border-transparent',
          spotlightTarget,
        )}
      >
        <div className="col-span-2 min-w-0">
          <VariablePicker
            conditionalOnly
            autoOpen={autoOpen}
            onSelect={chooseField}
            onDismiss={autoOpen ? onDismissNew : undefined}
          >
            <DataSelect
              id={`${id}-data`}
              field={field}
              placeholder={__('Choose data', 'yaymatik')}
              invalid={!!error && !field}
            />
          </VariablePicker>
        </div>
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              variant="ghost"
              size="icon-sm"
              className="self-center"
              aria-label={__('Remove rule', 'yaymatik')}
              onClick={onRemove}
            >
              <Trash2 />
            </Button>
          </TooltipTrigger>
          <TooltipContent>{__('Remove rule', 'yaymatik')}</TooltipContent>
        </Tooltip>

        <Select value={rule.operator} onValueChange={(operator) => onChange({ ...rule, operator })} disabled={!field}>
          <SelectTrigger id={`${id}-operator`} className="w-full" aria-label={__('Comparison', 'yaymatik')}>
            <SelectValue placeholder={__('Choose a comparison', 'yaymatik')} />
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
          <div data-spotlight className={cn('min-w-0', spotlightTarget)}>
            <label htmlFor={`${id}-value`} className="sr-only">
              {field?.label ?? __('Value', 'yaymatik')}
            </label>
            <ValueInput id={`${id}-value`} invalid={!!error} field={field} rule={rule} onChange={onChange} />
          </div>
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
