import { useRef } from 'react';
import { useForm, useStore } from '@tanstack/react-form';
import type * as v from 'valibot';
import { __ } from '@wordpress/i18n';

import type { StepFieldMeta } from '@/types/localize';
import { Field, FieldContent, FieldDescription, FieldGroup, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import type { Config, StepFormProps } from '../types';
import { InsertVariable } from './AddVariable';
import { isFieldVisible } from './catalogSchema';
import { ConfigField } from './ConfigField';
import { useMarkInvalid } from './feedback';
import { MultiSelect } from './MultiSelect';
import { useValidateOnMount } from './useValidateOnMount';

/** What one widget gets from the form. */
interface WidgetProps {
  field: StepFieldMeta;
  id: string;
  value: unknown;
  onChange: (value: unknown) => void;
  onBlur: () => void;
  invalid: boolean;
}

/**
 * The form of a step without a custom one, drawn from its catalog `fields` in order. Checks come
 * from `schema` (built from the same fields); a field hidden by `showIf` isn't drawn. `number_unit`
 * and `rules` aren't drawn here: a step using them brings its own form.
 */
export function StepForm({
  fields,
  schema,
  config,
  onChange,
}: StepFormProps<Config> & { fields: StepFieldMeta[]; schema: v.GenericSchema<Config> }) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);
  const markInvalid = useMarkInvalid();
  const values = useStore(form.store, (state) => state.values);

  return (
    <FieldGroup>
      {fields
        .filter((field) => isFieldVisible(field, values))
        .map((field) => {
          if (field.type === 'notice') return <Notice key={field.key} field={field} />;
          return (
            <form.Field key={field.key} name={field.key}>
              {(api) => (
                <Widget
                  field={field}
                  id={`step-field-${field.key}`}
                  value={api.state.value}
                  onChange={api.handleChange}
                  onBlur={api.handleBlur}
                  invalid={markInvalid(api.state.meta)}
                  errors={api.state.meta.errors}
                />
              )}
            </form.Field>
          );
        })}
    </FieldGroup>
  );
}

function Widget({ errors, ...props }: WidgetProps & { errors: ({ message?: string } | undefined)[] }) {
  const { field, id } = props;
  if (field.type === 'toggle') return <Toggle {...props} />;
  if (field.variables && ['text', 'textarea', 'emails'].includes(field.type)) {
    return <TextWithVariables {...props} errors={errors} />;
  }

  let control;
  switch (field.type) {
    case 'text':
    case 'emails':
      control = <TextInput {...props} />;
      break;
    case 'textarea':
      control = <TextArea {...props} />;
      break;
    case 'select':
      control = <SelectWidget {...props} />;
      break;
    case 'multiselect':
      control = (
        <MultiSelect
          id={id}
          options={field.options ?? []}
          value={Array.isArray(props.value) ? (props.value as string[]) : []}
          onChange={props.onChange}
          placeholder={field.placeholder ?? ''}
          invalid={props.invalid}
        />
      );
      break;
    case 'number':
      control = <NumberInput {...props} />;
      break;
    default:
      return null;
  }
  return (
    <ConfigField label={field.label} htmlFor={id} description={field.help} errors={errors}>
      {control}
    </ConfigField>
  );
}

/** Text that takes merge tags: "Insert variable" on the label row puts one at the cursor. */
function TextWithVariables({ errors, ...props }: WidgetProps & { errors: ({ message?: string } | undefined)[] }) {
  const { field, id, value, onChange } = props;
  const ref = useRef<HTMLInputElement & HTMLTextAreaElement>(null);
  const insert = <InsertVariable inputRef={ref} value={String(value ?? '')} onChange={onChange} />;
  return (
    <ConfigField label={field.label} htmlFor={id} description={field.help} action={insert} errors={errors}>
      {field.type === 'textarea' ? <TextArea {...props} inputRef={ref} /> : <TextInput {...props} inputRef={ref} />}
    </ConfigField>
  );
}

type TextProps = WidgetProps & { inputRef?: React.Ref<HTMLInputElement & HTMLTextAreaElement> };

function TextInput({ field, id, value, onChange, onBlur, invalid, inputRef }: TextProps) {
  return (
    <Input
      ref={inputRef}
      id={id}
      value={String(value ?? '')}
      placeholder={field.placeholder}
      onChange={(event) => onChange(event.target.value)}
      onBlur={onBlur}
      aria-invalid={invalid}
    />
  );
}

function TextArea({ field, id, value, onChange, onBlur, invalid, inputRef }: TextProps) {
  return (
    <Textarea
      ref={inputRef}
      id={id}
      rows={5}
      value={String(value ?? '')}
      placeholder={field.placeholder}
      onChange={(event) => onChange(event.target.value)}
      onBlur={onBlur}
      aria-invalid={invalid}
    />
  );
}

function SelectWidget({ field, id, value, onChange, invalid }: WidgetProps) {
  return (
    <Select value={String(value ?? '')} onValueChange={onChange}>
      <SelectTrigger id={id} className="w-full" aria-invalid={invalid}>
        <SelectValue placeholder={field.placeholder ?? __('Choose an option', 'yay-automation')} />
      </SelectTrigger>
      <SelectContent>
        {(field.options ?? []).map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

/** Blank is null, like PHP's `number` field. */
function NumberInput({ field, id, value, onChange, onBlur, invalid }: WidgetProps) {
  return (
    <Input
      id={id}
      type="number"
      inputMode="decimal"
      min={field.min}
      max={field.max}
      value={typeof value === 'number' ? value : ''}
      placeholder={field.placeholder}
      onChange={(event) => onChange(event.target.value === '' ? null : event.target.valueAsNumber)}
      onBlur={onBlur}
      aria-invalid={invalid}
    />
  );
}

function Toggle({ field, id, value, onChange }: WidgetProps) {
  return (
    <Field orientation="horizontal">
      <FieldContent>
        <FieldLabel htmlFor={id}>{field.label}</FieldLabel>
        {field.help && <FieldDescription>{field.help}</FieldDescription>}
      </FieldContent>
      <Switch id={id} size="sm" checked={value === true} onCheckedChange={onChange} />
    </Field>
  );
}

/** Read-only line, e.g. who an email is sent from: the label muted, then the text. */
function Notice({ field }: { field: StepFieldMeta }) {
  return (
    <p title={field.text} className="bg-muted min-w-0 truncate rounded-lg px-3 py-2.5 text-[13px]">
      <span className="text-muted-foreground">{field.label}</span>
      {'  '}
      {field.text}
    </p>
  );
}
