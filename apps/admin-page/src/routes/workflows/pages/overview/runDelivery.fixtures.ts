import type { RunDetail } from '@yaymatik/types';

/** Screen draft: open any workflow's overview with `?run=draft-delivery`. The plan's Builder deletes this file. */
export const DRAFT_RUN_ID = 'draft-delivery';

const step = (id: string, stepType: string, at: string, output: Record<string, unknown>) => ({
  id,
  runId: DRAFT_RUN_ID,
  stepId: id,
  iteration: 0,
  status: 'completed' as const,
  input: {},
  output,
  error: null,
  attempt: 1,
  startedAt: at,
  finishedAt: at,
  stepType: stepType as RunDetail['steps'][number]['stepType'],
});

export const draftRun = (workflowId: string): RunDetail => ({
  id: DRAFT_RUN_ID,
  workflowId,
  versionId: '1',
  triggerId: 'order_paid',
  subject: 'order:1042',
  status: 'completed',
  error: null,
  waitUntil: null,
  parentRunId: null,
  rootRunId: DRAFT_RUN_ID,
  depth: 0,
  startedAt: '2026-11-27T03:40:00Z',
  finishedAt: '2026-11-28T03:41:00Z',
  subjectLabel: 'Order #1042',
  subjectUrl: null,
  facts: [
    { label: 'Order', value: '#1042' },
    { label: 'Total', value: '$54.00' },
  ],
  stoppedByOrderUrl: null,
  steps: [
    step('s1', 'send_email', '2026-11-27T03:41:00Z', {
      to: 'jane@shop.com',
      delivery: { status: 'delivered', at: '2026-11-27T03:42:00Z' },
    }),
    step('s2', 'send_internal_email', '2026-11-27T03:41:05Z', {
      to: ['owner@shop.com'],
      delivery: { status: 'clicked', at: '2026-11-27T05:10:00Z' },
    }),
    step('s3', 'send_email', '2026-11-27T03:41:10Z', { to: 'jane@shop.com' }),
    step('s4', 'send_email', '2026-11-28T03:41:00Z', {
      to: 'jane@shop.com',
      delivery: { status: 'bounced', at: '2026-11-28T03:43:00Z', reason: '550 No such user here' },
    }),
  ],
});
