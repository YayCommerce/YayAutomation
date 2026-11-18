import { act } from 'react';
import meta from '@test-meta';
import type { StepId, WorkflowGraph } from '@yaymatik/types';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

/** Two plugins' triggers that both declare fact `amount`: money in one, text in the other. */
const trigger = (plugin: string, fact: Record<string, unknown>) => ({
  kind: 'trigger',
  plugin,
  provides: ['customer'],
  category: 'customer',
  label: `${plugin} trigger`,
  description: '',
  icon: '',
  summary: '',
  onError: 'stop',
  fields: [],
  outputs: [],
  facts: [{ key: 'trigger.amount', conditional: true, ...fact }],
});

async function load() {
  vi.resetModules();
  const automation = meta.automation;
  window.yayMatikMeta = {
    ...meta,
    automation: {
      ...automation,
      plugins: [
        ...automation.plugins,
        { id: 'acme', label: 'Acme', description: '', active: true, logo: '' },
        { id: 'other', label: 'Other', description: '', active: true, logo: '' },
      ],
      inactiveSteps: {
        gone_paid: {
          module: 'gone',
          plugin: 'Gone',
          kind: 'trigger',
          label: 'Gone paid',
          outputs: [],
          facts: ['amount'],
        },
      },
      steps: {
        ...automation.steps,
        // Registered first: before, its type was every workflow's.
        other_note: trigger('other', { label: 'Note amount', type: 'string', valueType: 'string' }),
        acme_paid: trigger('acme', { label: 'Paid amount', type: 'number', valueType: 'money' }),
      },
    },
  } as unknown as Window['yayMatikMeta'];
  return {
    ...(await import('..')),
    ...(await import('./marks')),
    ...(await import('./variables')),
    ...(await import('./fields/VariablePicker')),
    ...(await import('./fields/variableScope')),
    ...(await import('../conditions/criteria/Form')),
    ...(await import('../../model/validation')),
    ...(await import('@/components/ui/tooltip')),
  };
}

const config = (operator: string, value: string) => ({
  match: 'all',
  rules: [{ field: 'trigger.amount', operator, value, valueType: 'value' }],
});

const graphOn = (block: string, rule = config('equal', '10')) =>
  ({
    nodes: [
      { id: 't', kind: 'trigger', position: { x: 0, y: 0 }, data: { block, config: {} } },
      { id: 'c', kind: 'condition', position: { x: 0, y: 170 }, data: { block: 'condition', config: rule } },
    ],
    edges: [{ id: 't-c', source: 't', target: 'c' }],
  }) as unknown as WorkflowGraph;

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
  window.yayMatikMeta = meta as unknown as Window['yayMatikMeta'];
});

/** The scope "Insert variable" and the condition form get in a workflow on this trigger. */
const scopeOn = (mod: Awaited<ReturnType<typeof load>>, block: StepId) => ({
  variables: mod.availableVariables(graphOn(block), 'c'),
  trigger: { id: block, label: block, kind: 'trigger' as const, provides: ['customer'] },
  steps: {},
});

describe('a fact key two triggers declare with different types', () => {
  it('the condition editor uses the workflow’s trigger’s type', async () => {
    const mod = await load();
    const draw = (block: StepId) => {
      act(() =>
        root.render(
          <mod.TooltipProvider>
            <mod.VariableScopeContext.Provider value={scopeOn(mod, block)}>
              <mod.CriteriaForm key={block} config={config('equal', '10') as never} onChange={() => {}} />
            </mod.VariableScopeContext.Provider>
          </mod.TooltipProvider>,
        ),
      );
      return {
        text: container.textContent,
        input: container.querySelector<HTMLInputElement>('input[id$="-value"], input:not([type="hidden"])')?.type,
      };
    };

    expect(draw('acme_paid')).toMatchObject({ input: 'number' });
    expect(draw('acme_paid').text).toContain('Paid amount');
    expect(draw('other_note')).toMatchObject({ input: 'text' });
    expect(draw('other_note').text).toContain('Note amount');
  });

  it('the card summary names the fact of the workflow’s trigger', async () => {
    const { steps, plainSummary } = await load();
    const summary = (block: string) => plainSummary(steps.condition.summary(config('equal', '10'), block));

    expect(summary('acme_paid')).toBe('Paid amount is equal to 10');
    expect(summary('other_note')).toBe('Note amount is equal to 10');
  });

  it('VariablePicker offers the fact by the workflow’s trigger’s type', async () => {
    const mod = await load();
    const offered = (block: StepId) => {
      act(() =>
        root.render(
          <mod.VariableScopeContext.Provider value={scopeOn(mod, block)}>
            <mod.VariablePicker conditionalOnly filter={(field) => field.type === 'number'} onSelect={() => {}}>
              <button type="button">Pick</button>
            </mod.VariablePicker>
          </mod.VariableScopeContext.Provider>,
        ),
      );
      act(() => container.querySelector('button')!.click());
      const dialog = document.body.querySelector<HTMLElement>('[role="dialog"]')!;
      const section = [...dialog.querySelectorAll('button')].find(
        (b) => b.querySelector('.font-medium')?.textContent === 'Trigger',
      );
      if (section) act(() => section.click());
      const text = dialog.textContent ?? '';
      act(() => root.unmount());
      root = createRoot(container);
      return text;
    };

    expect(offered('acme_paid')).toContain('Paid amount');
    expect(offered('other_note')).not.toContain('amount');
  });

  it('validation checks a rule against the workflow’s trigger: valid on one, flagged on the other', async () => {
    const { validateWorkflow } = await load();
    const { messages } = await import('../../model/messages');
    const issue = (block: string, rule: ReturnType<typeof config>) =>
      validateWorkflow({ name: 'A', graph: graphOn(block, rule) }).find((i) => i.nodeId === 'c')?.message ?? null;
    const comparison = messages.ruleComparison;

    expect(issue('acme_paid', config('greater_than', '10'))).toBeNull();
    expect(issue('other_note', config('contains', 'vip'))).toBeNull();
    expect(issue('other_note', config('greater_than', '10'))).toBe(comparison);
    expect(issue('acme_paid', config('contains', 'vip'))).toBe(comparison);
  });

  it('a trigger whose plugin is off: fact rules aren’t checked, in the editor as in validation', async () => {
    const { steps, validateWorkflow } = await load();

    expect(steps.condition.firstError(config('greater_than', '10'), 'gone_paid')).toBeNull();
    expect(validateWorkflow({ name: 'A', graph: graphOn('gone_paid') }).find((i) => i.nodeId === 'c')).toBeUndefined();
  });
});
