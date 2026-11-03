import type { StepId, WorkflowEdge, WorkflowGraph, WorkflowNode } from '@yaymatik/types';
import { __ } from '@wordpress/i18n';

import { ROW_GAP } from '../../model/graph';
import { generateCouponStep } from '../../steps/actions/generate-coupon';
import type { SendEmailConfig } from '../../steps/actions/send-email/schema';
import type { WaitConfig, WaitUnit } from '../../steps/actions/wait/schema';
import type { Rule } from '../../steps/conditions/criteria/schema';
import { emailMessage } from './email';

interface FlowStep {
  id: string;
  stepId: StepId;
  config: Record<string, unknown>;
}

export const waitStep = (id: string, value: number, unit: WaitUnit): FlowStep => ({
  id,
  stepId: 'wait',
  config: { delay: { value, unit } } satisfies WaitConfig,
});

export const emailStep = (id: string, config: SendEmailConfig): FlowStep => ({ id, stepId: 'send_email', config });

/** 10% off for 7 days, read by the email below it as `steps.coupon`. */
export const couponStep = (): FlowStep => ({
  id: 'coupon',
  stepId: 'generate_coupon',
  config: { ...generateCouponStep.defaults(), amount: 10, expiry_days: 7 },
});

export const rule = (field: string, operator: string, value = ''): Rule => ({
  field,
  operator,
  value,
  valueType: 'value',
});

/** A trigger with no settings, then the steps in one line. */
export function lineGraph(trigger: StepId, steps: FlowStep[]): WorkflowGraph {
  const nodes: WorkflowNode[] = [
    { id: 'trigger', kind: 'trigger', position: { x: 0, y: 0 }, data: { block: trigger, config: {} } },
    ...steps.map(({ id, stepId, config }, i): WorkflowNode => ({
      id,
      kind: 'action',
      position: { x: 0, y: ROW_GAP * (i + 1) },
      data: { block: stepId, config },
    })),
  ];
  const edges: WorkflowEdge[] = nodes.slice(1).map((node, i) => ({
    id: `e-${nodes[i].id}-${node.id}`,
    source: nodes[i].id,
    target: node.id,
  }));
  return { nodes, edges };
}

export const COUPON_LINE = '{{ steps.coupon.box }}';
export const ITEM_LINES = ['{{ cart.items }}', '{{ cart.restore_button }}'];

/** Send email to the customer: a guest or a new account may have no first name. */
export const customerEmail = (subject: string, heading: string, lines: string[]): SendEmailConfig => ({
  to: '{{ customer.email }}',
  subject,
  heading,
  message: emailMessage([
    __('Hi {{ customer.first_name | default: "there" }},', 'yaymatik'),
    ...lines,
    __('See you soon,\n{{ site.name }}', 'yaymatik'),
  ]),
});
