import { useRef, type ReactNode } from 'react';
import { useForm } from '@tanstack/react-form';
import { Eye, Send } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { FieldDescription, FieldGroup } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { AddVariable } from '../../core/fields/AddVariable';
import { ConfigField } from '../../core/fields/ConfigField';
import { useMarkInvalid } from '../../core/fields/feedback';
import { useValidateOnMount } from '../../core/fields/useValidateOnMount';
import { comingSoonLabel } from '../../core/pending';
import type { BlockFormProps } from '../../core/types';
import { schema, type SendEmailConfig } from './schema';

const sender = window.yayAutomationMeta.automation.email;

type TextKey = 'to' | 'subject' | 'heading' | 'message';

export function SendEmailForm({ config, onChange }: BlockFormProps<SendEmailConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);
  const markInvalid = useMarkInvalid();
  const refs = {
    to: useRef<HTMLInputElement>(null),
    subject: useRef<HTMLInputElement>(null),
    heading: useRef<HTMLInputElement>(null),
    message: useRef<HTMLTextAreaElement>(null),
  };

  const textField = (name: TextKey, label: string, description?: string, placeholder?: string) => (
    <form.Field name={name}>
      {(field) => (
        <ConfigField
          label={label}
          htmlFor={`block-email-${name}`}
          description={description}
          errors={field.state.meta.errors}
        >
          {name === 'message' ? (
            <Textarea
              ref={refs.message}
              id="block-email-message"
              rows={10}
              value={field.state.value}
              onChange={(event) => field.handleChange(event.target.value)}
              onBlur={field.handleBlur}
              aria-invalid={markInvalid(field.state.meta)}
            />
          ) : (
            <Input
              ref={refs[name]}
              id={`block-email-${name}`}
              value={field.state.value}
              placeholder={placeholder}
              onChange={(event) => field.handleChange(event.target.value)}
              onBlur={field.handleBlur}
              aria-invalid={markInvalid(field.state.meta)}
            />
          )}
          <AddVariable inputRef={refs[name]} value={field.state.value} onChange={field.handleChange} />
        </ConfigField>
      )}
    </form.Field>
  );

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

      {textField('to', __('To', 'yay-automation'), __('Separate addresses with commas.', 'yay-automation'))}
      {textField('subject', __('Subject', 'yay-automation'))}
      {textField(
        'heading',
        __('Heading', 'yay-automation'),
        __('Shown at the top of the email. Leave blank to use the subject.', 'yay-automation'),
      )}
      {textField(
        'message',
        __('Message', 'yay-automation'),
        __('An unsubscribe link is added automatically.', 'yay-automation'),
      )}

      <div className="flex gap-2">
        <ComingSoonButton icon={<Eye />} label={__('Preview', 'yay-automation')} />
        <ComingSoonButton icon={<Send />} label={__('Send test', 'yay-automation')} />
      </div>
    </FieldGroup>
  );
}

/** Needs a REST endpoint the backend doesn't have yet. */
function ComingSoonButton({ icon, label }: { icon: ReactNode; label: string }) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        {/* Disabled buttons swallow pointer events, so a wrapper carries the tooltip. */}
        <span tabIndex={0}>
          <Button variant="outline" size="sm" disabled>
            {icon}
            {label}
          </Button>
        </span>
      </TooltipTrigger>
      <TooltipContent>{comingSoonLabel}</TooltipContent>
    </Tooltip>
  );
}
