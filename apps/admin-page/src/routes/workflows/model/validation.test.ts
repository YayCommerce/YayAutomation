import type { WorkflowGraph } from '@yaymatik/types';
import { describe, expect, it } from 'vitest';

import { stepOf } from '../steps';
import highValueOrderAlert from '../templates/high-value-order-alert';
import { addNode, removeNode, setNodeStep, updateNodeConfig } from './graph';
import {
  activateIssues,
  activateIssueTexts,
  availableData,
  canActivate,
  editedIssues,
  editedWarnings,
  issueText,
  newStepIssues,
  serverWarning,
  stepsNeedingSetup,
  validateWorkflow,
  withServerIssues,
} from './validation';

const base = (): WorkflowGraph => structuredClone(highValueOrderAlert.graph);
/** Steps and edges by id, so editing the template doesn't change what a test covers. */
const node = (graph: WorkflowGraph, id: string) => graph.nodes.find((n) => n.id === id)!;
const codes = (graph: WorkflowGraph, name = 'Alert') => validateWorkflow({ name, graph }).map((issue) => issue.code);

describe('validateWorkflow', () => {
  it('finds nothing in a complete workflow', () => {
    expect(validateWorkflow({ name: 'Alert', graph: base() })).toEqual([]);
  });

  it('needs a name, ignoring tags', () => {
    expect(codes(base(), ' <b></b> ')).toEqual(['name_required']);
  });

  it('needs a chosen trigger', () => {
    const graph = base();
    node(graph, 'trigger').data.block = null;
    expect(codes(graph)).toEqual(['no_trigger']);
  });

  it('needs an action', () => {
    expect(codes(removeNode(base(), 'email'))).toEqual(['no_action']);
  });

  it('lists unlinked steps in one workflow-level issue', () => {
    const issues = validateWorkflow({ name: 'Alert', graph: removeNode(base(), 'condition') });
    expect(issues).toEqual([expect.objectContaining({ code: 'unlinked', nodeIds: ['email'] })]);
  });

  it('flags an action with no step chosen', () => {
    const graph = addNode(base(), 'email', 'action', undefined, 'next');
    expect(validateWorkflow({ name: 'Alert', graph })).toEqual([
      expect.objectContaining({ code: 'no_block', nodeId: 'next' }),
    ]);
  });

  it('flags invalid settings on the step', () => {
    const graph = base();
    node(graph, 'email').data.config = { ...node(graph, 'email').data.config, subject: '' };
    expect(validateWorkflow({ name: 'Alert', graph })).toEqual([
      expect.objectContaining({ code: 'invalid_config', nodeId: 'email' }),
    ]);
  });

  // Same rules as PHP `Validator::tag_error()` / `reach()`.
  describe('merge tags', () => {
    const withMessage = (message: string) => {
      const graph = base();
      node(graph, 'email').data.config = { ...node(graph, 'email').data.config, message };
      return validateWorkflow({ name: 'Alert', graph });
    };
    // Each names the field that holds the tag, like PHP.
    const issue = (code: string, message: string) => [{ code, message, field: 'message', nodeId: 'email' }];

    it('accepts deep paths, trigger paths, filters, outputs of a step above and a relation itself', () => {
      expect(
        withMessage(
          'Hi {{ order.customer.first_name | default: "there" }} {{ trigger.order.total | money }} {{ steps.condition.result }} {{ order.customer }}',
        ),
      ).toEqual([]);
    });

    it('flags a malformed tag, an unknown filter and an unknown path', () => {
      expect(withMessage('{{ order. }}')).toEqual(
        issue('invalid_config', '{{ order. }} is written incorrectly. Use {{ variable }} or {{ variable | filter }}.'),
      );
      expect(withMessage('{{ order.total | shout }}')).toEqual(
        issue('invalid_config', 'shout isn’t a filter. Available filters: default, upper, lower, join, date, money.'),
      );
      expect(withMessage('{{ order.nope }}')).toEqual(
        issue('invalid_config', '{{ order.nope }} isn’t an available variable.'),
      );
    });

    it('flags an output of a missing step or of a missing key', () => {
      expect(withMessage('{{ steps.gone.result }}')).toEqual(
        issue(
          'invalid_config',
          '{{ steps.gone.result }} comes from a step that isn’t in this workflow anymore. Add that step above this one, or remove the variable.',
        ),
      );
      expect(withMessage('{{ steps.condition.nope }}')).toEqual(
        issue('invalid_config', '{{ steps.condition.nope }} isn’t an available variable.'),
      );
    });

    it("flags an output of a step that isn't above", () => {
      const graph = addNode(base(), 'condition', 'action', 'otherwise', 'note');
      node(graph, 'note').data = { block: 'add_order_note', config: { type: 'private', note: 'Big order' } };
      node(graph, 'email').data.config = { ...node(graph, 'email').data.config, message: '{{ steps.note.type }}' };
      expect(validateWorkflow({ name: 'Alert', graph })).toEqual([
        {
          code: 'missing_data',
          message:
            '{{ steps.note.type }} needs the Add order note step above this one. Move it above, or remove the variable.',
          field: 'message',
          nodeId: 'email',
        },
      ]);
    });

    it('says when an email uses a coupon with no Generate coupon above', () => {
      const uses = (graph: WorkflowGraph) => {
        node(graph, 'email').data.config = { ...node(graph, 'email').data.config, message: '{{ steps.coupon.code }}' };
        return validateWorkflow({ name: 'Alert', graph }).map(({ message }) => message);
      };
      expect(uses(base())).toEqual([
        '{{ steps.coupon.code }} comes from a step that isn’t in this workflow anymore. Add that step above this one, or remove the variable.',
      ]);

      const elsewhere = addNode(base(), 'condition', 'action', 'otherwise', 'coupon');
      node(elsewhere, 'coupon').data = {
        block: 'generate_coupon',
        config: stepOf({ block: 'generate_coupon', config: {} })!.defaults(),
      };
      expect(uses(elsewhere)).toEqual([
        '{{ steps.coupon.code }} needs the Generate coupon step above this one. Move it above, or remove the variable.',
      ]);
    });
  });
});

describe('availableData', () => {
  it('is null until a trigger is chosen', () => {
    const graph = base();
    node(graph, 'trigger').data.block = null;
    expect(availableData(graph)).toBeNull();
  });

  it("gives every step the trigger's data plus the always-available items", () => {
    const at = availableData(base())!;
    expect(at('email')).toEqual(expect.arrayContaining(['order', 'customer', 'site']));
  });
});

describe('activateIssues', () => {
  const noAction = { code: 'no_action', message: 'Add at least one action.' } as const;
  const noName = { code: 'name_required', message: 'Give this workflow a name.' } as const;
  const codesOf = (workflow: Parameters<typeof activateIssues>[0]) => activateIssues(workflow).map((i) => i.code);

  it("reads the server's issues of the saved content and the draft, each issue once", () => {
    expect(codesOf({ status: 'inactive', issues: { live: [], draft: [noAction] } })).toEqual(['no_action']);
    expect(codesOf({ status: 'inactive', issues: { live: [noAction], draft: null } })).toEqual(['no_action']);
    expect(codesOf({ status: 'inactive', issues: { live: [noAction], draft: [noAction] } })).toEqual(['no_action']);
    expect(codesOf({ status: 'inactive', issues: { live: [noName], draft: [noAction] } })).toEqual([
      'name_required',
      'no_action',
    ]);
    expect(codesOf({ status: 'inactive', issues: { live: [], draft: [] } })).toEqual([]);
  });

  it('canActivate: only an inactive workflow with no issues', () => {
    expect(canActivate({ status: 'inactive', issues: { live: [], draft: null } })).toBe(true);
    expect(canActivate({ status: 'inactive', issues: { live: [noAction], draft: null } })).toBe(false);
    expect(canActivate({ status: 'active', issues: { live: [], draft: null } })).toBe(false);
  });

  it('finds nothing for an active workflow', () => {
    expect(codesOf({ status: 'active', issues: { live: [], draft: [noAction] } })).toEqual([]);
  });
});

describe('stepsNeedingSetup', () => {
  it("counts each step with its own issue once; unconnected and workflow-level issues aren't counted", () => {
    expect(
      stepsNeedingSetup([
        { code: 'invalid_config', message: '', nodeId: 'a' },
        { code: 'missing_data', message: '', nodeId: 'a' },
        { code: 'unlinked', message: '', nodeIds: ['b', 'c'] },
        { code: 'no_action', message: '' },
      ]),
    ).toBe(1);
  });
});

describe('newStepIssues', () => {
  // A note step above the email, whose message uses the note's output.
  const withNote = () => {
    const graph = addNode(base(), 'condition', 'action', 'then', 'note');
    node(graph, 'note').data = { block: 'add_order_note', config: { type: 'private', note: 'Big order' } };
    const email = graph.edges.find((edge) => edge.target === 'email')!;
    email.source = 'note';
    email.sourceHandle = undefined;
    node(graph, 'email').data.config = { ...node(graph, 'email').data.config, message: '{{ steps.note.type }}' };
    return graph;
  };

  it('lists the steps a swap breaks, not the swapped step', () => {
    const before = withNote();
    expect(validateWorkflow({ name: 'Alert', graph: before })).toEqual([]);
    const after = setNodeStep(before, 'note', 'change_order_status');
    expect(newStepIssues([], { name: 'Alert', graph: after }, 'note')).toEqual(['email']);
  });

  it('skips issues a step already had', () => {
    const before = withNote();
    node(before, 'email').data.config = { ...node(before, 'email').data.config, message: '{{ steps.note.nope }}' };
    const had = validateWorkflow({ name: 'Alert', graph: before });
    const after = setNodeStep(before, 'note', 'change_order_status');
    expect(newStepIssues(had, { name: 'Alert', graph: after }, 'note')).toEqual([]);
  });
});

describe('issueText', () => {
  const emailLabel = () => stepOf(node(base(), 'email').data)!.label;
  const onEmail = { code: 'invalid_config', nodeId: 'email', message: 'Add a recipient.' } as const;
  // A step only the draft has: Change order status, added below the email.
  const draft = () =>
    setNodeStep(addNode(base(), 'email', 'action', undefined, 'extra'), 'extra', 'change_order_status');
  const onExtra = { code: 'invalid_config', nodeId: 'extra', message: 'Choose an option from the list.' } as const;

  it("puts the step's name before a step's issue", () => {
    expect(issueText(onEmail, base())).toBe(`${emailLabel()}: Add a recipient.`);
  });

  it('leaves an issue alone when it names its step already, has none, or the step is gone', () => {
    const named = { ...onEmail, message: `${emailLabel()} needs a recipient.` };
    expect(issueText(named, base())).toBe(named.message);
    expect(issueText({ code: 'no_action', message: 'Add at least one action.' }, base())).toBe(
      'Add at least one action.',
    );
    expect(issueText(onExtra, base())).toBe(onExtra.message);
  });

  it('looks the step up in the graphs in order', () => {
    expect(issueText(onExtra, base(), draft())).toBe('Change order status: Choose an option from the list.');
  });

  it('activateIssueTexts: live issues from the live graph, draft issues from the draft, each line once', () => {
    const workflow = {
      status: 'inactive' as const,
      graph: base(),
      draft: draft(),
      issues: { live: [onEmail, onExtra], draft: [onEmail, onExtra] },
    };
    expect(activateIssueTexts(workflow)).toEqual([
      `${emailLabel()}: Add a recipient.`,
      onExtra.message,
      'Change order status: Choose an option from the list.',
    ]);
    expect(activateIssueTexts({ ...workflow, status: 'active' })).toEqual([]);
  });
});

describe('withServerIssues', () => {
  // A rule only PHP checks, e.g. a percentage over 100.
  const percent = {
    code: 'invalid_config',
    message: 'Enter a percentage from 1 to 100.',
    field: 'amount',
    nodeId: 'email',
  } as const;
  const checked = base();

  it('adds a step issue only the server found while the step is unchanged', () => {
    expect(withServerIssues([], { graph: checked, issues: [percent] }, checked)).toEqual([percent]);
    const moved = { ...checked, nodes: checked.nodes.map((n) => ({ ...n, position: { x: 1, y: 1 }, data: n.data })) };
    expect(withServerIssues([], { graph: checked, issues: [percent] }, moved)).toEqual([percent]);
  });

  it('adds cart tracking being off, which only the server knows', () => {
    const off = { code: 'cart_tracking_off', message: 'Turn on cart tracking.', nodeId: 'trigger' } as const;
    expect(withServerIssues([], { graph: checked, issues: [off] }, checked)).toEqual([off]);
  });

  it('drops it once the step is edited, until the next save checks it again', () => {
    const edited = updateNodeConfig(checked, 'email', { ...node(checked, 'email').data.config, subject: 'New' });
    expect(withServerIssues([], { graph: checked, issues: [percent] }, edited)).toEqual([]);
  });

  it("keeps the editor's own issue for a step and the server's other issues out", () => {
    const own = { code: 'invalid_config', message: 'Add a subject.', nodeId: 'email' } as const;
    expect(withServerIssues([own], { graph: checked, issues: [percent] }, checked)).toEqual([own]);
    const missing = {
      code: 'missing_data',
      message: 'Order data isn’t available at this step.',
      nodeId: 'email',
    } as const;
    expect(
      withServerIssues([], { graph: checked, issues: [missing, { code: 'no_action', message: 'x' }] }, checked),
    ).toEqual([]);
  });

  it("reads the draft's issues while there is a draft, else the live graph's", () => {
    const issues = { live: [{ code: 'no_action', message: 'live' }], draft: [percent] } as const;
    expect(editedIssues({ draft: checked, issues: { live: [...issues.live], draft: [...issues.draft] } })).toEqual([
      percent,
    ]);
    expect(editedIssues({ draft: null, issues: { live: [...issues.live], draft: null } })).toEqual(issues.live);
  });
});

describe('warnings', () => {
  const warning = { code: 'may_refire', nodeId: 'a', message: 'x' } as const;
  const graph: WorkflowGraph = {
    nodes: [{ id: 'a', kind: 'action', position: { x: 0, y: 0 }, data: { block: 'change_order_status', config: {} } }],
    edges: [],
  };

  it("reads the draft's warnings while there is a draft, else the live graph's", () => {
    expect(editedWarnings({ draft: graph, warnings: { live: [], draft: [warning] } })).toEqual([warning]);
    expect(editedWarnings({ draft: graph, warnings: { live: [warning], draft: null } })).toEqual([]);
    expect(editedWarnings({ draft: null, warnings: { live: [warning], draft: null } })).toEqual([warning]);
  });

  it('counts while the step is the one the server checked', () => {
    const server = { graph, issues: [], warnings: [warning] };
    expect(serverWarning(server, graph, 'a')).toEqual(warning);
    expect(serverWarning(server, graph, 'b')).toBeUndefined();
    const edited = { ...graph, nodes: [{ ...graph.nodes[0], data: { ...graph.nodes[0].data } }] };
    expect(serverWarning(server, edited, 'a')).toBeUndefined();
  });
});
