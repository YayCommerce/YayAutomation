import { afterEach, describe, expect, it, vi } from 'vitest';

import meta from '../../../test/fixtures/meta.json';

/** A step another plugin's module sends in PHP's catalog: no TS folder of its own. */
const moduleStep = {
  kind: 'action',
  plugin: 'yaymail',
  category: 'email',
  requires: ['order'],
  label: 'Send YayMail email',
  description: 'Send an email built with a YayMail template.',
  icon: 'mail',
  summary: 'Send %template%',
  onError: 'stop',
  fields: [
    {
      key: 'template',
      type: 'select',
      label: 'Template',
      options: [{ value: 'thank_you', label: 'Thank you' }],
      default: 'thank_you',
    },
  ],
  outputs: [],
};

async function loadSteps() {
  vi.resetModules();
  const automation = meta.automation;
  window.yayMatikMeta = {
    ...meta,
    automation: {
      ...automation,
      plugins: [...automation.plugins, { id: 'yaymail', label: 'YayMail', description: '', active: true, logo: '' }],
      steps: { ...automation.steps, yaymail_send_template: moduleStep },
    },
  } as unknown as Window['yayMatikMeta'];
  return (await import('.')).steps;
}

describe('module steps', () => {
  afterEach(() => {
    window.yayMatikMeta = meta as unknown as Window['yayMatikMeta'];
  });

  it('registers a catalog step without a TS folder, with its form and checks from its fields', async () => {
    const step = (await loadSteps()).yaymail_send_template;

    expect(step).toMatchObject({ kind: 'action', plugin: 'yaymail', category: 'email', label: 'Send YayMail email' });
    expect(step.defaults()).toEqual({ template: 'thank_you' });
    expect(step.firstError(step.defaults())).toBeNull();
    expect(step.Form).toBeDefined();
  });
});
