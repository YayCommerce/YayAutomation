import * as v from 'valibot';
import { __ } from '@wordpress/i18n';

/** Mirrors src/Engine/Blocks/Actions/SendAdminEmail.php. Blank `recipients` = the site admin email. */
export type SendAdminEmailConfig = { recipients: string; subject: string; body: string };

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

export const splitRecipients = (recipients: string) =>
  recipients
    .split(',')
    .map((email) => email.trim())
    .filter(Boolean);

const required = (message: string) =>
  v.pipe(
    v.string(),
    v.check((value) => value.trim() !== '', message),
  );

export const schema = v.object({
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

export const defaults = (): SendAdminEmailConfig => ({
  recipients: '',
  subject: __('Order #{{order_number}} is {{order_status}}', 'yay-automation'),
  body: __(
    'Order #{{order_number}} from {{customer_name}} <{{customer_email}}> is {{order_status}}.\nTotal: {{order_total}}\n\nView order: {{order_admin_url}}',
    'yay-automation',
  ),
});
