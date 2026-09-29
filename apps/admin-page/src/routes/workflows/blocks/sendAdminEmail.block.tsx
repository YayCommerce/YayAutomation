import { useForm } from '@tanstack/react-form';
import * as v from 'valibot';
import { __, sprintf } from '@wordpress/i18n';

import { FieldGroup } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ConfigField, defineBlock, useValidateOnMount, type BlockFormProps } from './block';

/** Mirrors src/Engine/Blocks/SendAdminEmail.php. Blank `recipients` = the site admin email. */
type SendAdminEmailConfig = { recipients: string; subject: string; body: string };

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

/**
 * Port of WordPress `is_email()`, which the PHP block validates with. Not `v.email()`: it's stricter
 * (rejects e.g. `o'brien@example.com`), so the UI would block addresses the server accepts.
 */
function isWpEmail(email: string) {
  if (email.length < 6 || email.indexOf('@', 1) === -1) return false;
  const at = email.indexOf('@');
  const local = email.slice(0, at);
  const domain = email.slice(at + 1);
  if (!/^[a-zA-Z0-9!#$%&'*+/=?^_`{|}~.-]+$/.test(local)) return false;
  if (/\.{2,}/.test(domain) || /^[\s.]|[\s.]$/.test(domain)) return false;
  const subs = domain.split('.');
  return subs.length >= 2 && subs.every((sub) => /^[a-z0-9-]+$/i.test(sub) && !/^-|-$/.test(sub));
}

const splitRecipients = (recipients: string) =>
  recipients
    .split(',')
    .map((email) => email.trim())
    .filter(Boolean);

const required = (message: string) =>
  v.pipe(
    v.string(),
    v.check((value) => value.trim() !== '', message),
  );

const schema = v.object({
  recipients: v.pipe(
    v.string(),
    v.check(
      (recipients) => splitRecipients(recipients).every(isWpEmail),
      __('Enter valid email addresses, separated by commas.', 'yay-automation'),
    ),
  ),
  subject: required(__('Enter a subject.', 'yay-automation')),
  body: required(__('Enter a message.', 'yay-automation')),
});

function SendAdminEmailForm({ config, onChange }: BlockFormProps<SendAdminEmailConfig>) {
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

export const sendAdminEmailBlock = defineBlock<SendAdminEmailConfig>({
  id: 'send_admin_email',
  kind: 'action',
  label: __('Send admin email', 'yay-automation'),
  description: __('Sends a plain-text email about the order to the store team.', 'yay-automation'),
  schema,
  defaults: () => ({
    recipients: '',
    subject: __('Order #{{order_number}} is {{order_status}}', 'yay-automation'),
    body: __(
      'Order #{{order_number}} from {{customer_name}} <{{customer_email}}> is {{order_status}}.\nTotal: {{order_total}}\n\nView order: {{order_admin_url}}',
      'yay-automation',
    ),
  }),
  summary: ({ recipients }) => {
    const emails = splitRecipients(recipients);
    return emails.length
      ? /* translators: %s: comma-separated email addresses */
        sprintf(__('Email to %s', 'yay-automation'), emails.join(', '))
      : __('Email to the site admin', 'yay-automation');
  },
  Form: SendAdminEmailForm,
});
