import type { Workflow } from '@yay-automation/types';

/**
 * In-memory stand-in for the REST API. Same async signatures a real `api.get/post` wrapper
 * would have, so swapping it out only touches `workflow.query.ts`.
 */

const demoWorkflow: Workflow = {
  id: 'demo',
  name: 'Tag VIP customers',
  status: 'draft',
  graph: {
    nodes: [
      {
        id: 'trigger',
        kind: 'trigger',
        position: { x: 0, y: 0 },
        data: { title: 'Order created', description: 'Runs when a new order is placed' },
      },
      {
        id: 'condition',
        kind: 'condition',
        position: { x: 0, y: 150 },
        data: { title: 'Order total > $100' },
      },
      {
        id: 'action-vip',
        kind: 'action',
        position: { x: -150, y: 300 },
        data: { title: 'Tag customer VIP' },
      },
      {
        id: 'action-email',
        kind: 'action',
        position: { x: 150, y: 300 },
        data: { title: 'Send follow-up email' },
      },
    ],
    edges: [
      { id: 'e-trigger-condition', source: 'trigger', target: 'condition' },
      { id: 'e-condition-vip', source: 'condition', target: 'action-vip', sourceHandle: 'then' },
      { id: 'e-condition-email', source: 'condition', target: 'action-email', sourceHandle: 'otherwise' },
    ],
  },
};

const db = new Map<string, Workflow>([[demoWorkflow.id, demoWorkflow]]);

const delay = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

export async function getWorkflow(id: string): Promise<Workflow> {
  await delay(400);
  const workflow = db.get(id);
  if (!workflow) {
    throw new Error(`Workflow "${id}" not found`);
  }
  // Clone so callers never mutate the "server" copy.
  return structuredClone(workflow);
}

export async function saveWorkflow(workflow: Workflow): Promise<Workflow> {
  await delay(400);
  db.set(workflow.id, structuredClone(workflow));
  return structuredClone(workflow);
}
