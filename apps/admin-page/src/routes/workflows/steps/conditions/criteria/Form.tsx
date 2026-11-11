import { useContext, useState } from 'react';
import { Check, CircleAlert, Info, Plus, Trash2 } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { fieldByKey, isRelative, needsValue, operatorLabels, operatorsFor, takesList } from '../../core/data';
import { errorStyles, FieldIssueContext, useShowErrors } from '../../core/fields/feedback';
import { spotlightTarget, VariablePicker } from '../../core/fields/VariablePicker';
import type { StepFormProps } from '../../core/types';
import {
  criteriaErrors,
  emptyGroup,
  emptyRule,
  isGroup,
  joinerText,
  ruleForField,
  withOperator,
  type CriteriaConfig,
  type CriteriaItem,
  type Group,
  type GroupErrors,
  type Rule,
} from './schema';
import { DataSelect, MetaKeyInput, ValueInput } from './ValueInput';

/**
 * The condition as a sentence: "Take the Yes path when [all / any] of these match:", one box per rule
 * (data, comparison, value) or group joined by "and" / "or", then "Otherwise the No path runs." A rule
 * added with "Add rule" opens its data picker at once and goes away if nothing is picked. The condition
 * always keeps one item: deleting the last one empties it instead.
 */
export function CriteriaForm({ config, onChange }: StepFormProps<CriteriaConfig>) {
  const [value, setValue] = useState(config);
  // The rule just added by "Add rule", until its data is picked.
  const [pending, setPending] = useState<number | null>(null);
  const update = (next: CriteriaConfig) => {
    setValue(next);
    onChange(next);
  };
  const setItem = (index: number, item: CriteriaItem) =>
    update({ ...value, rules: value.rules.map((r, i) => (i === index ? item : r)) });
  const removeItem = (index: number) =>
    update({
      ...value,
      rules: value.rules.length === 1 ? [emptyRule()] : value.rules.filter((_, i) => i !== index),
    });
  const addRule = () => {
    setPending(value.rules.length);
    update({ ...value, rules: [...value.rules, emptyRule()] });
  };
  // A condition starts with one blank rule; left beside the group, it would block Activate unseen.
  const addGroup = () => {
    const [only] = value.rules;
    const blank = value.rules.length === 1 && !isGroup(only) && !only.field;
    update({ ...value, rules: blank ? [emptyGroup()] : [...value.rules, emptyGroup()] });
  };

  const showErrors = useShowErrors();
  const errors = withRuleIssue(criteriaErrors(value.rules), useContext(FieldIssueContext));
  const errorFor = (index: number) => (showErrors ? errors[index] : null);

  return (
    <div className="flex flex-col gap-3.5">
      <div className="flex flex-wrap items-center gap-1.5 text-sm/7.5">
        {
          /* translators: 1: the Yes path's tag, 2: "all" or "any" */ __(
            'Take the %1$s path when %2$s of these match:',
            'yaymatik',
          )
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
                <MatchSelect key={i} match={value.match} onChange={(match) => update({ ...value, match })} />
              ) : (
                part.trim() && <span key={i}>{part.trim()}</span>
              ),
            )
        }
      </div>

      <div className="flex flex-col gap-2">
        {value.rules.map((item, index) => (
          <div key={index} className="flex flex-col gap-2">
            {index > 0 && <Joiner match={value.match} />}
            {isGroup(item) ? (
              <GroupBox
                id={`group-${index}`}
                group={item}
                errors={errorFor(index) as GroupErrors | null}
                onChange={(next) => setItem(index, next)}
                onRemove={() => removeItem(index)}
              />
            ) : (
              <RuleBox
                id={`rule-${index}`}
                rule={item}
                error={errorFor(index) as string | null}
                autoOpen={index === pending}
                onChange={(next) => {
                  setItem(index, next);
                  if (index === pending) setPending(null);
                }}
                onDismissNew={() => {
                  setPending(null);
                  update({ ...value, rules: value.rules.filter((_, i) => i !== index) });
                }}
                onRemove={() => removeItem(index)}
              />
            )}
          </div>
        ))}
      </div>

      <div className="flex flex-wrap gap-2">
        <Button variant="outline" size="sm" onClick={addRule}>
          <Plus />
          {__('Add rule', 'yaymatik')}
        </Button>
        <Button variant="outline" size="sm" onClick={addGroup}>
          <Plus />
          {__('Add group', 'yaymatik')}
        </Button>
      </div>

      <p className="text-muted-foreground flex items-center gap-1.5 text-[12.5px]">
        <Info className="size-3.25 shrink-0" aria-hidden />
        {__('Otherwise the No path runs.', 'yaymatik')}
      </p>
    </div>
  );
}

/**
 * Puts a server issue under the rule it names (only PHP knows the protected custom fields), when the
 * form has no error of its own there.
 */
function withRuleIssue(
  errors: (string | GroupErrors | null)[],
  issue: { field?: string; rule?: number[]; message: string } | undefined,
) {
  const [item, inner] = issue?.field === 'rules' && issue.rule ? issue.rule : [];
  if (item === undefined || !(item in errors)) return errors;
  const at = errors[item];
  if (inner === undefined) {
    return at === null ? errors.map((error, i) => (i === item ? issue!.message : error)) : errors;
  }
  if (!at || typeof at === 'string' || !(inner in at.rules) || at.rules[inner]) return errors;
  const rules = at.rules.map((error, i) => (i === inner ? issue!.message : error));
  return errors.map((error, i) => (i === item ? { ...at, rules } : error));
}

function MatchSelect({ match, onChange }: { match: Group['match']; onChange: (match: Group['match']) => void }) {
  return (
    <Select value={match} onValueChange={(next) => onChange(next as Group['match'])}>
      <SelectTrigger size="sm" className="h-7.5 px-2.5" aria-label={__('Match', 'yaymatik')}>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        <SelectItem value="all">{__('all', 'yaymatik')}</SelectItem>
        <SelectItem value="any">{__('any', 'yaymatik')}</SelectItem>
      </SelectContent>
    </Select>
  );
}

function Joiner({ match }: { match: Group['match'] }) {
  return (
    <span className="text-muted-foreground pl-2.5 text-[11.5px] font-semibold tracking-wider uppercase">
      {joinerText[match]}
    </span>
  );
}

/** Rules joined by their own all / any. No "Add group" here: groups are one level deep. Its last rule can go: an empty group needs setup until it's removed. */
function GroupBox({
  id,
  group,
  errors,
  onChange,
  onRemove,
}: {
  id: string;
  group: Group;
  /** Null while the form shows no errors. */
  errors: GroupErrors | null;
  onChange: (group: Group) => void;
  onRemove: () => void;
}) {
  const [pending, setPending] = useState<number | null>(null);
  const setRules = (rules: Rule[]) => onChange({ ...group, rules });
  const tone = errors?.own ? errorStyles : null;

  return (
    <div className="flex flex-col gap-1.5">
      <div className={cn('bg-card flex flex-col gap-2 rounded-[10px] border p-2.5', tone?.tint)}>
        <div className="flex items-center gap-1.5 text-sm">
          <MatchSelect match={group.match} onChange={(match) => onChange({ ...group, match })} />
          <span className="flex-1">{__('of these', 'yaymatik')}</span>
          <Tooltip>
            <TooltipTrigger asChild>
              <Button variant="ghost" size="icon-sm" aria-label={__('Remove group', 'yaymatik')} onClick={onRemove}>
                <Trash2 />
              </Button>
            </TooltipTrigger>
            <TooltipContent>{__('Remove group', 'yaymatik')}</TooltipContent>
          </Tooltip>
        </div>
        {group.rules.map((rule, index) => (
          <div key={index} className="flex flex-col gap-2">
            {index > 0 && <Joiner match={group.match} />}
            <RuleBox
              id={`${id}-rule-${index}`}
              rule={rule}
              error={errors?.rules[index] ?? null}
              autoOpen={index === pending}
              onChange={(next) => {
                setRules(group.rules.map((r, i) => (i === index ? next : r)));
                if (index === pending) setPending(null);
              }}
              onDismissNew={() => {
                setPending(null);
                setRules(group.rules.filter((_, i) => i !== index));
              }}
              onRemove={() => setRules(group.rules.filter((_, i) => i !== index))}
            />
          </div>
        ))}
        <Button
          variant="ghost"
          size="sm"
          className="w-fit pl-1"
          onClick={() => {
            setPending(group.rules.length);
            setRules([...group.rules, emptyRule()]);
          }}
        >
          <Plus />
          {__('Add rule to group', 'yaymatik')}
        </Button>
      </div>
      {errors?.own && tone && <ErrorLine className={tone.text}>{errors.own}</ErrorLine>}
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

  const hasValue = needsValue(rule.operator);
  const meta = field?.type === 'meta';
  const keyMissing = meta && !rule.key?.trim();
  // These take the whole width; a plain value sits beside its comparison.
  const wide = !hasValue || takesList(rule.operator) || isRelative(rule.operator);

  const operatorSelect = (className: string) => (
    <Select
      value={rule.operator}
      onValueChange={(operator) => onChange(withOperator(rule, operator))}
      disabled={!field}
    >
      <SelectTrigger id={`${id}-operator`} className={className} aria-label={__('Comparison', 'yaymatik')}>
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
  );

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
            onSelect={(key) => onChange(ruleForField(rule, key))}
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

        {meta ? (
          <div className="col-span-3 grid min-w-0 grid-cols-2 gap-2">
            <MetaKeyInput
              id={`${id}-key`}
              invalid={!!error && keyMissing}
              field={field}
              rule={rule}
              onChange={onChange}
            />
            {operatorSelect('w-full')}
          </div>
        ) : (
          operatorSelect(wide ? 'col-span-3 w-full' : 'w-full')
        )}

        {hasValue && (
          <div data-spotlight className={cn('min-w-0', (wide || meta) && 'col-span-3', spotlightTarget)}>
            <label htmlFor={`${id}-value`} className="sr-only">
              {field?.label ?? __('Value', 'yaymatik')}
            </label>
            <ValueInput
              id={`${id}-value`}
              invalid={!!error && !keyMissing}
              field={field}
              rule={rule}
              onChange={onChange}
            />
          </div>
        )}
      </div>
      {field?.help && <p className="text-muted-foreground px-1 text-xs">{field.help}</p>}
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
