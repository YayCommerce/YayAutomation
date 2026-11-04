import { act } from 'react';
import { useStore } from '@tanstack/react-form';
import type { Workflow, WorkflowGraph, WorkflowIssue } from '@yaymatik/types';
import { createRoot, type Root } from 'react-dom/client';
import { MemoryRouter } from 'react-router';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { addNode, updateNodeConfig } from '../../model/graph';
import { sameIssues, validateWorkflow, withServerIssues } from '../../model/validation';
import type { Feedback } from '../../steps/core/fields/feedback';
import highValueOrderAlert from '../../templates/high-value-order-alert';
import { StepPanel } from './StepPanel';
import { useStepPanel } from './useStepPanel';
import { useWorkflowForm, WorkflowFormContext, type WorkflowForm } from './useWorkflowForm';

// Tells React this is a test, so `act()` flushes updates without warnings.
(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

const live = (): WorkflowGraph => structuredClone(highValueOrderAlert.graph);

type Editor = { form: WorkflowForm; panel: ReturnType<typeof useStepPanel> };

/** The editor's form and panel, without the canvas and the requests around them. */
function Harness({
  graph,
  editor,
  feedback = 'none',
  serverIssues = [],
  warning,
}: {
  graph: WorkflowGraph;
  editor: Partial<Editor>;
  feedback?: Feedback;
  /** What the server found in `graph`. */
  serverIssues?: WorkflowIssue[];
  warning?: string;
}) {
  const form = useWorkflowForm({ draft: graph, graph } as Workflow);
  const panel = useStepPanel(form, () => {});
  Object.assign(editor, { form, panel });
  const open = panel.panel;
  // As the editor finds them.
  const issues = useStore(
    form.store,
    (state) =>
      withServerIssues(
        validateWorkflow({ name: 'Test', graph: state.values.graph }),
        { graph, issues: serverIssues },
        state.values.graph,
      ),
    sameIssues,
  );
  return (
    <WorkflowFormContext.Provider value={form}>
      {open && (
        <div data-testid="panel">
          <StepPanel
            key={`${open.nodeId}:${panel.formVersion}`}
            workflowId="1"
            nodeId={open.nodeId}
            picking={open.picking}
            feedback={feedback}
            issue={issues.find((issue) => issue.nodeId === open.nodeId)}
            warning={warning}
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

const mount = (
  graph: WorkflowGraph,
  nodeId: string,
  feedback?: Feedback,
  serverIssues?: WorkflowIssue[],
  warning?: string,
) => {
  const editor: Partial<Editor> = {};
  act(() =>
    root.render(
      // The cart trigger's notes link to Settings.
      <MemoryRouter>
        <Harness graph={graph} editor={editor} feedback={feedback} serverIssues={serverIssues} warning={warning} />
      </MemoryRouter>,
    ),
  );
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

describe('reopening a saved Generate coupon', () => {
  const withCoupon = (config: Record<string, unknown>) => {
    const graph = addNode(live(), 'email', 'action', undefined, 'coupon');
    graph.nodes.find((node) => node.id === 'coupon')!.data = { block: 'generate_coupon', config };
    return graph;
  };
  const saved = {
    discount_type: 'percent',
    amount: 30,
    expiry_days: 30,
    minimum_spend: null,
    individual_use: false,
    limit_to_customer: true,
    code_prefix: 'YM',
  };

  it.each([
    ['as PHP saves it', saved],
    ['saved with numbers as text', { ...saved, amount: '30', expiry_days: '30', minimum_spend: '' }],
  ])('shows no errors and keeps the draft clean (%s)', (_, config) => {
    const graph = withCoupon(config);
    // Shows field errors, as for a step left unfinished.
    const editor = mount(graph, 'coupon', 'review');
    expect(inputValues()).toContain('30');
    act(() => editor.panel.setPanel(null));
    act(() => editor.panel.setPanel({ nodeId: 'coupon', picking: false }));

    expect(container.querySelector('[aria-invalid="true"]')).toBeNull();
    expect(container.textContent).not.toMatch(/Invalid type|Expected/);
    expect(editor.form.state.values.graph).toBe(graph);
  });
});

describe('an issue only the server found', () => {
  it('shows under its field', () => {
    const graph = addNode(live(), 'email', 'action', undefined, 'coupon');
    graph.nodes.find((node) => node.id === 'coupon')!.data = {
      block: 'generate_coupon',
      config: { discount_type: 'percent', amount: 150, expiry_days: 30, minimum_spend: null },
    };
    const issue = {
      code: 'invalid_config',
      message: 'Enter a percentage from 1 to 100.',
      field: 'amount',
      nodeId: 'coupon',
    } as const;
    mount(graph, 'coupon', 'review', [issue]);

    const amount = container.querySelector('#step-field-amount')!;
    expect(amount.getAttribute('aria-invalid')).toBe('true');
    expect(amount.closest('[data-slot="field"]')?.textContent ?? container.textContent).toContain(issue.message);
    expect(container.querySelectorAll('[aria-invalid="true"]')).toHaveLength(1);
  });
});

describe('a warning from the server', () => {
  it('shows a line in the step’s panel', () => {
    mount(live(), 'email', 'none', [], 'This step can set off this workflow’s own trigger.');
    expect(container.textContent).toContain('This step can set off this workflow’s own trigger.');
  });
});

describe('cart tracking being off', () => {
  it('leaves the warning to the trigger notes, without a red notice', () => {
    const graph = live();
    const trigger = graph.nodes.find((node) => node.kind === 'trigger')!;
    trigger.data = { block: 'cart_abandoned', config: {} };
    const issue = { code: 'cart_tracking_off', message: 'Turn on cart tracking.', nodeId: trigger.id } as const;
    mount(graph, trigger.id, 'review', [issue]);

    expect(container.textContent).toContain('Cart tracking is off, so this trigger never starts.');
    expect(container.textContent).not.toContain(issue.message);
  });
});

/** The panel's error notice above the form, if any. */
const notice = () => container.querySelector('[data-slot="alert"]')?.textContent ?? null;

describe('a step issue in its panel', () => {
  const sendEmail = (message: string) => {
    // Send email on the No path; Generate coupon under the alert on the Yes path.
    let graph = addNode(live(), 'condition', 'action', 'otherwise', 'customer');
    graph = addNode(graph, 'email', 'action', undefined, 'coupon');
    const data = (id: string) => graph.nodes.find((node) => node.id === id)!;
    data('coupon').data = { block: 'generate_coupon', config: { discount_type: 'percent', amount: 10 } };
    data('customer').data = {
      block: 'send_email',
      config: { to: '{{ customer.email }}', subject: 'Thanks', heading: '', message },
    };
    return graph;
  };

  it('shows a variable issue under the field that holds the tag', () => {
    mount(sendEmail('<p>Your code: {{ steps.coupon.code }}</p>'), 'customer', 'review');

    const message = [...container.querySelectorAll('[data-slot="field"]')].find((field) =>
      field.querySelector('label')?.textContent?.startsWith('Message'),
    )!;
    expect(message.textContent).toContain('{{ steps.coupon.code }} needs the Generate coupon step above this one.');
    expect(message.querySelector('.yaymatik-rich-editor.ring-destructive')).not.toBeNull();
    expect(notice()).toBeNull();
  });

  it('stays quiet while the step is being set up', () => {
    mount(sendEmail('<p>{{ steps.coupon.code }}</p>'), 'customer', 'none');
    expect(container.textContent).not.toContain('needs the Generate coupon step');
  });

  it("shows a condition's rule error once, in its rule", () => {
    const graph = live();
    graph.nodes.find((node) => node.id === 'condition')!.data.config = {
      match: 'all',
      rules: [{ field: 'order.total', operator: 'greater_or_equal', value: '', valueType: 'value' }],
    };
    mount(graph, 'condition', 'review');

    expect(container.textContent).toContain('Enter a value for Order total.');
    expect(notice()).toBeNull();
  });

  it('shows an issue no field of the form holds at the top', () => {
    const issue = {
      code: 'invalid_config',
      message: 'Order data isn’t available at this step.',
      field: 'rules',
      nodeId: 'condition',
    } as const;
    mount(live(), 'condition', 'review', [issue]);
    expect(notice()).toBe(issue.message);
  });
});
