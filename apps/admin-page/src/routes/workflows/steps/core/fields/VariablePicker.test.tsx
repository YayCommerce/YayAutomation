import { act } from 'react';
import type { StepId, WorkflowGraph } from '@yaymatik/types';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { availableVariables } from '../variables';
import { VariablePicker } from './VariablePicker';
import { VariableScopeContext, type VariableScope } from './variableScope';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

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
});

const node = (id: string, kind: 'trigger' | 'action', block: StepId) => ({
  id,
  kind,
  position: { x: 0, y: 0 },
  data: { block, config: {} },
});
const graph: WorkflowGraph = {
  nodes: [
    node('t', 'trigger', 'order_created'),
    node('c', 'action', 'generate_coupon'),
    node('m', 'action', 'send_email'),
  ],
  edges: [
    { id: 't-c', source: 't', target: 'c' },
    { id: 'c-m', source: 'c', target: 'm' },
  ],
};
const scope: VariableScope = {
  variables: availableVariables(graph, 'm'),
  trigger: { id: 'order_created', label: 'Order created', kind: 'trigger', provides: ['order', 'customer'] },
  steps: { c: { label: 'Generate coupon', kind: 'action', summary: '10% off' } },
};

/** Opens the picker and returns the dialog. */
function open(blocks: boolean) {
  act(() =>
    root.render(
      <VariableScopeContext.Provider value={scope}>
        <VariablePicker blocks={blocks} onSelect={() => {}}>
          <button type="button">Insert variable</button>
        </VariablePicker>
      </VariableScopeContext.Provider>,
    ),
  );
  act(() => container.querySelector('button')!.click());
  return document.body.querySelector<HTMLElement>('[role="dialog"]')!;
}

const rowNamed = (dialog: HTMLElement, label: string) =>
  [...dialog.querySelectorAll('button')].find((b) => b.querySelector('.font-medium')?.textContent === label);

describe('VariablePicker blocks', () => {
  it('lists the product grids under their data item and the coupon box under its step', () => {
    const dialog = open(true);
    act(() => rowNamed(dialog, 'Blocks')!.click());
    const headings = [...dialog.querySelectorAll('p.uppercase')].map((p) => p.textContent);
    expect(headings).toEqual(['Order', 'Site', '10% off']);
    const text = dialog.textContent ?? '';
    expect(text).toContain('Cross-sell products');
    expect(text).toContain('Upsell products');
  });

  it('keeps blocks out of the order’s own fields, and out of fields that take no blocks', () => {
    const withBlocks = open(true);
    act(() => rowNamed(withBlocks, 'Order')!.click());
    expect(withBlocks.textContent).not.toContain('Cross-sell products');

    act(() => root.unmount());
    root = createRoot(container);
    const plain = open(false);
    expect(rowNamed(plain, 'Blocks')).toBeUndefined();
  });
});
