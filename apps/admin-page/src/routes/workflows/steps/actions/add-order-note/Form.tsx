import { useRef } from 'react';
import { useForm } from '@tanstack/react-form';
import { __ } from '@wordpress/i18n';

import { FieldGroup } from '@/components/ui/field';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { AddVariable } from '../../core/fields/AddVariable';
import { ConfigField } from '../../core/fields/ConfigField';
import { useMarkInvalid } from '../../core/fields/feedback';
import { useValidateOnMount } from '../../core/fields/useValidateOnMount';
import type { StepFormProps } from '../../core/types';
import { schema, type AddOrderNoteConfig } from './schema';

export function AddOrderNoteForm({ config, onChange }: StepFormProps<AddOrderNoteConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);
  const markInvalid = useMarkInvalid();
  const noteRef = useRef<HTMLTextAreaElement>(null);

  return (
    <FieldGroup>
      <form.Field name="type">
        {(field) => (
          <ConfigField
            label={__('Note type', 'yay-automation')}
            htmlFor="step-note-type"
            errors={field.state.meta.errors}
          >
            <Select
              value={field.state.value}
              onValueChange={(type) => field.handleChange(type as AddOrderNoteConfig['type'])}
            >
              <SelectTrigger id="step-note-type" className="w-full">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="private">{__('Private note', 'yay-automation')}</SelectItem>
                <SelectItem value="customer">{__('Note to customer', 'yay-automation')}</SelectItem>
              </SelectContent>
            </Select>
          </ConfigField>
        )}
      </form.Field>

      <form.Field name="note">
        {(field) => (
          <ConfigField label={__('Note', 'yay-automation')} htmlFor="step-note-text" errors={field.state.meta.errors}>
            <Textarea
              ref={noteRef}
              id="step-note-text"
              rows={5}
              value={field.state.value}
              onChange={(event) => field.handleChange(event.target.value)}
              onBlur={field.handleBlur}
              aria-invalid={markInvalid(field.state.meta)}
            />
            <AddVariable inputRef={noteRef} value={field.state.value} onChange={field.handleChange} />
          </ConfigField>
        )}
      </form.Field>
    </FieldGroup>
  );
}
