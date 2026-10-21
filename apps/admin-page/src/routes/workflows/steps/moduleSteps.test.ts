import { afterEach, describe, expect, it, vi } from 'vitest';

import meta from '../../../test/fixtures/meta.json';

/** A step another plugin's module sends in PHP's catalog: no TS folder of its own. */
const moduleStep = {
  kind: 'action',
  plugin: 'example',
  category: 'email',
  requires: ['order'],
  label: 'Send example email',
  description: 'Send an email built with an example template.',
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
      plugins: [...automation.plugins, { id: 'example', label: 'Example', description: '', active: true, logo: '' }],
      steps: { ...automation.steps, example_send_template: moduleStep },
    },
  } as unknown as Window['yayMatikMeta'];
  return (await import('.')).steps;
}

describe('module steps', () => {
  afterEach(() => {
    window.yayMatikMeta = meta as unknown as Window['yayMatikMeta'];
  });

  it('registers a catalog step without a TS folder, with its form and checks from its fields', async () => {
    const step = (await loadSteps()).example_send_template;

    expect(step).toMatchObject({ kind: 'action', plugin: 'example', category: 'email', label: 'Send example email' });
    expect(step.defaults()).toEqual({ template: 'thank_you' });
    expect(step.firstError(step.defaults())).toBeNull();
    expect(step.Form).toBeDefined();
  });
});
