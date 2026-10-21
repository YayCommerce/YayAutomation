import { useContext } from 'react';
import { useForm, useStore } from '@tanstack/react-form';
import type * as v from 'valibot';
import { __ } from '@wordpress/i18n';

import type { StepFieldMeta } from '@/types/localize';
import { FieldGroup } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { Config, StepFormProps } from '../types';
import { isFieldVisible } from './catalogSchema';
import { ConfigField } from './ConfigField';
import { FieldIssueContext, useMarkInvalid, useShowErrors } from './feedback';
import { MultiSelect } from './MultiSelect';
import { RichTextEditor } from './RichTextEditor';
import { useValidateOnMount } from './useValidateOnMount';
import { Notice, TextField, ToggleField } from './widgets';

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
  const showErrors = useShowErrors();
  // A rule only the server checks (e.g. a percentage over 100), after the last save.
  const fieldIssue = useContext(FieldIssueContext);
  const values = useStore(form.store, (state) => state.values);

  return (
    <FieldGroup>
      {fields
        .filter((field) => isFieldVisible(field, values))
        .map((field) => {
          if (field.type === 'notice') return <Notice key={field.key} label={field.label} text={field.text ?? ''} />;
          return (
            <form.Field key={field.key} name={field.key}>
              {(api) => (
                <Widget
                  field={field}
                  id={`step-field-${field.key}`}
                  value={api.state.value}
                  onChange={api.handleChange}
                  onBlur={api.handleBlur}
                  invalid={markInvalid(api.state.meta) || (showErrors && fieldIssue?.field === field.key)}
                  errors={[...api.state.meta.errors, fieldIssue?.field === field.key ? fieldIssue : undefined]}
                />
              )}
            </form.Field>
          );
        })}
    </FieldGroup>
  );
}

function Widget({ errors, ...props }: WidgetProps & { errors: ({ message?: string } | undefined)[] }) {
  const { field, id, value, onChange, onBlur, invalid } = props;
  const labelled = (control: React.ReactNode) => (
    <ConfigField label={field.label} htmlFor={id} description={field.help} errors={errors}>
      {control}
    </ConfigField>
  );
  switch (field.type) {
    case 'toggle':
      return <ToggleField id={id} label={field.label} help={field.help} checked={value === true} onChange={onChange} />;
    case 'html':
      return (
        <RichTextEditor
          id={id}
          label={field.label}
          help={field.help}
          variables={!!field.variables}
          value={String(value ?? '')}
          onChange={onChange}
          onBlur={onBlur}
          invalid={invalid}
          errors={errors}
        />
      );
    case 'text':
    case 'emails':
    case 'textarea':
      return (
        <TextField
          id={id}
          label={field.label}
          help={field.help}
          placeholder={field.placeholder}
          multiline={field.type === 'textarea'}
          variables={!!field.variables}
          value={String(value ?? '')}
          onChange={onChange}
          onBlur={onBlur}
          invalid={invalid}
          errors={errors}
        />
      );
    case 'select':
      return labelled(<SelectWidget {...props} />);
    case 'multiselect':
      return labelled(
        <MultiSelect
          id={id}
          options={field.options ?? []}
          value={Array.isArray(value) ? (value as string[]) : []}
          onChange={onChange}
          placeholder={field.placeholder ?? ''}
          invalid={invalid}
        />,
      );
    case 'number':
      return labelled(<NumberInput {...props} />);
    default:
      return null;
  }
}

function SelectWidget({ field, id, value, onChange, invalid }: WidgetProps) {
  return (
    <Select value={String(value ?? '')} onValueChange={onChange}>
      <SelectTrigger id={id} className="w-full" aria-invalid={invalid}>
        <SelectValue placeholder={field.placeholder ?? __('Choose an option', 'yaymatik')} />
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
