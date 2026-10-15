import { act } from 'react';
import type { Workflow, WorkflowGraph } from '@yay-automation/types';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { addNode, updateNodeConfig } from '../../model/graph';
import highValueOrderAlert from '../../templates/high-value-order-alert';
import { StepPanel } from './StepPanel';
import { useStepPanel } from './useStepPanel';
import { useWorkflowForm, WorkflowFormContext, type WorkflowForm } from './useWorkflowForm';

// Tells React this is a test, so `act()` flushes updates without warnings.
(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const live = (): WorkflowGraph => structuredClone(highValueOrderAlert.graph);

type Editor = { form: WorkflowForm; panel: ReturnType<typeof useStepPanel> };

/** The editor's form and panel, without the canvas and the requests around them. */
function Harness({ graph, editor }: { graph: WorkflowGraph; editor: Partial<Editor> }) {
  const form = useWorkflowForm({ draft: graph, graph } as Workflow);
  const panel = useStepPanel(form, () => {});
  Object.assign(editor, { form, panel });
  const open = panel.panel;
  return (
    <WorkflowFormContext.Provider value={form}>
      {open && (
        <div data-testid="panel">
          <StepPanel
            key={`${open.nodeId}:${panel.formVersion}`}
            nodeId={open.nodeId}
            picking={open.picking}
            feedback="none"
            closable
            onPick={() => {}}
            onSwap={() => {}}
            onClose={() => {}}
          />
        </div>
      )}
    </WorkflowFormContext.Provider>
  );
}

/** What a discard does to the editor: the form and the open panel go back to `graph`. */
const discardTo = (editor: Editor, graph: WorkflowGraph) =>
  act(() => {
    editor.form.reset({ graph });
    editor.panel.reset(graph);
  });

const inputValues = () =>
  [...container.querySelectorAll('input, textarea')].map((el) => (el as HTMLInputElement).value);

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

const mount = (graph: WorkflowGraph, nodeId: string) => {
  const editor: Partial<Editor> = {};
  act(() => root.render(<Harness graph={graph} editor={editor} />));
  act(() => editor.panel!.setPanel({ nodeId, picking: false }));
  return editor as Editor;
};

describe('discarding draft changes with a step panel open', () => {
  it('shows the discarded step’s saved values in a field form', () => {
    const draft = updateNodeConfig(live(), 'email', {
      ...live().nodes.find((node) => node.id === 'email')!.data.config,
      subject: 'Draft subject',
    });
    const editor = mount(draft, 'email');
    expect(inputValues()).toContain('Draft subject');

    discardTo(editor, live());

    expect(inputValues()).not.toContain('Draft subject');
    expect(inputValues()).toContain(live().nodes.find((node) => node.id === 'email')!.data.config.subject);
  });

  it('shows the saved rules in the condition form', () => {
    const draft = updateNodeConfig(live(), 'condition', {
      match: 'all',
      rules: [{ field: 'order.total', operator: 'greater_or_equal', value: '900' }],
    });
    const editor = mount(draft, 'condition');
    expect(inputValues()).toContain('900');

    discardTo(editor, live());

    expect(inputValues()).not.toContain('900');
    expect(inputValues()).toContain('500');
  });

  it('goes back from the step picker to the step’s settings', () => {
    const editor = mount(live(), 'email');
    act(() => editor.panel.setPanel({ nodeId: 'email', picking: true }));

    discardTo(editor, live());

    expect(editor.panel.panel).toEqual({ nodeId: 'email', picking: false });
  });

  it('closes the panel of a step that only the draft had', () => {
    const draft = addNode(live(), 'email', 'action', undefined, 'extra');
    const editor = mount(draft, 'extra');
    expect(container.querySelector('[data-testid="panel"]')).not.toBeNull();

    discardTo(editor, live());

    expect(editor.panel.panel).toBeNull();
    expect(container.querySelector('[data-testid="panel"]')).toBeNull();
  });
});
