import { act } from 'react';
import type { WorkflowTemplate } from '@yaymatik/types';
import { createRoot } from 'react-dom/client';
import { MemoryRouter, Route, Routes } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';

import meta from '../../../test/fixtures/meta.json';

vi.mock('../shared/canvas/ReadOnlyCanvas', () => ({ ReadOnlyCanvas: () => null }));
vi.mock('../pages/template-preview/useCreateDraft', () => ({
  installFirst: () => null,
  useCreateDraft: () => ({ createDraft: () => {}, isCreating: false }),
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

/** Acme's email action: the design is left for the store owner. */
const emailStep = {
  kind: 'action',
  plugin: 'acme_rewards',
  category: 'email',
  requires: ['customer'],
  email: 'customer',
  result: 'Sent to %to%',
  label: 'Send rewards email',
  description: 'Sends an email designed in Acme Rewards.',
  icon: '',
  summary: 'Email “%subject%”',
  onError: 'stop',
  fields: [
    {
      key: 'subject',
      type: 'text',
      label: 'Subject',
      required: true,
      requiredMessage: '%s needs a subject.',
      variables: true,
      default: '',
    },
    { key: 'design_id', type: 'select', label: 'Design', required: true, options: [], default: '' },
  ],
  outputs: [{ key: 'to', label: 'Sent to', type: 'string' }],
};

/** As PHP `Catalog::templates()` sends it. */
const welcome: WorkflowTemplate = {
  id: 'acme_rewards_welcome_bonus',
  name: 'Welcome bonus points',
  description: 'Give new customers 100 points and tell them by email.',
  category: 'customer_lifecycle',
  workflow: 'acme_rewards_welcome_bonus',
  createdAt: '2026-12-01',
  before: ['Pick or design the email in Acme Rewards after you add the template.'],
  plugin: 'acme_rewards',
  graph: {
    nodes: [
      {
        id: 'trigger',
        kind: 'trigger',
        position: { x: 0, y: 0 },
        data: { block: 'customer_account_created', config: {} },
      },
      {
        id: 'email',
        kind: 'action',
        position: { x: 0, y: 170 },
        data: { block: 'acme_rewards_send_email', config: { subject: 'Welcome!', design_id: '' } },
      },
    ],
    edges: [{ id: 'e-trigger-email', source: 'trigger', target: 'email' }],
  },
};

/** Loads the app's modules with Acme in the meta. */
async function withAcme() {
  vi.resetModules();
  const { automation } = meta;
  window.yayMatikMeta = {
    ...meta,
    automation: {
      ...automation,
      plugins: [
        ...automation.plugins,
        { id: 'acme_rewards', label: 'Acme Rewards', description: '', active: true, logo: 'https://x.test/logo.svg' },
      ],
      steps: { ...automation.steps, acme_rewards_send_email: emailStep },
      templates: [welcome],
    },
  } as unknown as Window['yayMatikMeta'];
  return {
    templates: await import('.'),
    validation: await import('../model/validation'),
  };
}

const render = (node: React.ReactNode) => {
  const container = document.body.appendChild(document.createElement('div'));
  const root = createRoot(container);
  act(() => root.render(node));
  return {
    container,
    unmount: () => {
      act(() => root.unmount());
      container.remove();
    },
  };
};

describe('another plugin’s templates', () => {
  afterEach(() => {
    window.yayMatikMeta = meta as unknown as Window['yayMatikMeta'];
  });

  it('join the library after core’s, and a draft from one has the issue on its step', async () => {
    const { templates, validation } = await withAcme();

    expect(templates.templates.at(-1)).toBe(welcome);
    expect(templates.findTemplate(welcome.id)).toBe(welcome);
    expect(templates.rankOf(welcome.workflow)).toBeGreaterThan(templates.rankOf('high_value_order_alert'));
    // What Activate refuses until the design is picked; the draft itself saves.
    expect(validation.validateWorkflow({ name: welcome.name, graph: welcome.graph })).toEqual([
      expect.objectContaining({ code: 'invalid_config', nodeId: 'email', field: 'design_id' }),
    ]);
  });

  it('show in their plugin’s group, with its name and logo', async () => {
    await withAcme();
    const { TemplatesDialog } = await import('../pages/list/library/TemplatesDialog');
    const view = render(
      <MemoryRouter>
        <TemplatesDialog open onOpenChange={() => {}} />
      </MemoryRouter>,
    );

    const group = document.querySelector('section[aria-label="Acme Rewards"]')!;
    expect(group.querySelector('h3')!.textContent).toContain('Acme Rewards');
    expect(group.querySelector('h3 [style*="logo.svg"]')).not.toBeNull();
    expect(group.textContent).toContain('Welcome bonus points');
    view.unmount();
  });

  it('list the plugin’s lines and the steps to set up under Before you start', async () => {
    await withAcme();
    const { default: TemplatePreviewPage } = await import('../pages/template-preview/TemplatePreviewPage');
    const view = render(
      <MemoryRouter initialEntries={[`/workflows/templates/${welcome.id}`]}>
        <Routes>
          <Route path="/workflows/templates/:templateId" element={<TemplatePreviewPage />} />
        </Routes>
      </MemoryRouter>,
    );

    expect(view.container.textContent).toContain('Before you start');
    expect(view.container.textContent).toContain(
      'Pick or design the email in Acme Rewards after you add the template.',
    );
    expect(view.container.textContent).toContain('Set up Send rewards email before you activate.');
    expect(view.container.textContent).toContain('Using it creates a draft. Review the emails, then activate it.');
    view.unmount();
  });
});
