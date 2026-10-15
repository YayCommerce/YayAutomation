import type { ReactNode } from 'react';
import { useForm } from '@tanstack/react-form';
import { Eye, Send } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { FieldGroup } from '@/components/ui/field';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useMarkInvalid } from '../../core/fields/feedback';
import { useValidateOnMount } from '../../core/fields/useValidateOnMount';
import { Notice, TextField } from '../../core/fields/widgets';
import { comingSoonLabel } from '../../core/pending';
import type { StepFormProps } from '../../core/types';
import { schema, type SendEmailConfig } from './schema';

const { fromLine } = window.yayAutomationMeta.automation.email;

type TextKey = 'to' | 'subject' | 'heading' | 'message';

export function SendEmailForm({ config, onChange }: StepFormProps<SendEmailConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);
  const markInvalid = useMarkInvalid();

  const textField = (name: TextKey, label: string, help?: string) => (
    <form.Field name={name}>
      {(field) => (
        <TextField
          id={`step-email-${name}`}
          label={label}
          help={help}
          multiline={name === 'message'}
          variables
          value={field.state.value}
          onChange={field.handleChange}
          onBlur={field.handleBlur}
          invalid={markInvalid(field.state.meta)}
          errors={field.state.meta.errors}
        />
      )}
    </form.Field>
  );

  return (
    <FieldGroup>
      <Notice label={__('From', 'yay-automation')} text={fromLine} />

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
