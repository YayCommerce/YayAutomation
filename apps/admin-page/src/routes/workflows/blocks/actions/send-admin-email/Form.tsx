import { useForm } from '@tanstack/react-form';
import { __ } from '@wordpress/i18n';

import { FieldGroup } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ConfigField } from '../../core/fields/ConfigField';
import { useValidateOnMount } from '../../core/fields/useValidateOnMount';
import type { BlockFormProps } from '../../core/types';
import { schema, type SendAdminEmailConfig } from './schema';

/** Must match `SendAdminEmail::merge_tags()` in PHP. */
const mergeTags = [
  '{{order_number}}',
  '{{order_total}}',
  '{{order_status}}',
  '{{customer_name}}',
  '{{customer_email}}',
  '{{order_admin_url}}',
  '{{site_name}}',
];

const adminEmail = window.yayAutomationMeta.wp.adminEmail;

export function SendAdminEmailForm({ config, onChange }: BlockFormProps<SendAdminEmailConfig>) {
  const form = useForm({
    defaultValues: config,
    validators: { onChange: schema },
    listeners: { onChange: ({ formApi }) => onChange(formApi.state.values) },
  });
  useValidateOnMount(form);

  return (
    <FieldGroup>
      <form.Field name="recipients">
        {(field) => (
          <ConfigField
            label={__('Recipients', 'yay-automation')}
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
              id="block-email-subject"
              value={field.state.value}
              onChange={(event) => field.handleChange(event.target.value)}
              onBlur={field.handleBlur}
              aria-invalid={!field.state.meta.isValid}
            />
          </ConfigField>
        )}
      </form.Field>

      <form.Field name="body">
        {(field) => (
          <ConfigField
            label={__('Message', 'yay-automation')}
            htmlFor="block-email-body"
            description={
              <>
                {__('Sent as plain text. Merge tags:', 'yay-automation')}{' '}
                {mergeTags.map((tag, index) => (
                  <span key={tag}>
                    {index > 0 && ', '}
                    <code className="text-foreground">{tag}</code>
                  </span>
                ))}
              </>
            }
            errors={field.state.meta.errors}
          >
            <Textarea
              id="block-email-body"
              rows={9}
              value={field.state.value}
              onChange={(event) => field.handleChange(event.target.value)}
              onBlur={field.handleBlur}
              aria-invalid={!field.state.meta.isValid}
            />
          </ConfigField>
        )}
      </form.Field>
    </FieldGroup>
  );
}
