import { useRef } from 'react';
import { useForm } from '@tanstack/react-form';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { FieldDescription, FieldGroup } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { AddVariable } from '../../core/fields/AddVariable';
import { ConfigField } from '../../core/fields/ConfigField';
import { useValidateOnMount } from '../../core/fields/useValidateOnMount';
import type { BlockFormProps } from '../../core/types';
import { schema, type SendInternalEmailConfig } from './schema';

const { adminEmail } = window.yayAutomationMeta.wp;
const sender = window.yayAutomationMeta.automation.email;

export function SendInternalEmailForm({ config, onChange, available }: BlockFormProps<SendInternalEmailConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);
  const subjectRef = useRef<HTMLInputElement>(null);
  const messageRef = useRef<HTMLTextAreaElement>(null);

  return (
    <FieldGroup>
      <ConfigField label={__('From', 'yay-automation')} htmlFor="block-email-from" errors={[]}>
        <Input id="block-email-from" value={sender.fromAddress} readOnly disabled />
        <FieldDescription className="flex justify-between gap-2">
          {__('The store email used to contact customers', 'yay-automation')}
          <Button asChild variant="link" size="sm" className="h-auto px-0">
            <a href={sender.settingsUrl} target="_blank" rel="noreferrer">
              {__('Change store email', 'yay-automation')}
            </a>
          </Button>
        </FieldDescription>
      </ConfigField>

      <form.Field name="recipients">
        {(field) => (
          <ConfigField
            label={__('To', 'yay-automation')}
            htmlFor="block-email-recipients"
            description={__(
              'Separate addresses with commas. Leave blank to use the site admin email.',
              'yay-automation',
            )}
            errors={field.state.meta.errors}
          >
            <Input
              id="block-email-recipients"
              value={field.state.value}
              placeholder={adminEmail}
              onChange={(event) => field.handleChange(event.target.value)}
              onBlur={field.handleBlur}
              aria-invalid={!field.state.meta.isValid}
            />
          </ConfigField>
        )}
      </form.Field>

      <form.Field name="subject">
        {(field) => (
          <ConfigField
            label={__('Subject', 'yay-automation')}
            htmlFor="block-email-subject"
            errors={field.state.meta.errors}
          >
            <Input
              ref={subjectRef}
              id="block-email-subject"
              value={field.state.value}
              onChange={(event) => field.handleChange(event.target.value)}
              onBlur={field.handleBlur}
              aria-invalid={!field.state.meta.isValid}
            />
            <AddVariable
              available={available}
              inputRef={subjectRef}
              value={field.state.value}
              onChange={field.handleChange}
            />
          </ConfigField>
        )}
      </form.Field>

      <form.Field name="message">
        {(field) => (
          <ConfigField
            label={__('Message', 'yay-automation')}
            htmlFor="block-email-message"
            errors={field.state.meta.errors}
          >
            <Textarea
              ref={messageRef}
              id="block-email-message"
              rows={10}
              value={field.state.value}
              onChange={(event) => field.handleChange(event.target.value)}
              onBlur={field.handleBlur}
              aria-invalid={!field.state.meta.isValid}
            />
            <AddVariable
              available={available}
              inputRef={messageRef}
              value={field.state.value}
              onChange={field.handleChange}
            />
          </ConfigField>
        )}
      </form.Field>
    </FieldGroup>
  );
}
