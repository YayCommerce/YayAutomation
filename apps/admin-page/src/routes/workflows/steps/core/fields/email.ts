/**
 * Port of WordPress `is_email()`, which the PHP steps validate with. Not `v.email()`: it's stricter
 * (rejects e.g. `o'brien@example.com`), so the UI would block addresses the server accepts.
 */
export function isWpEmail(email: string) {
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
