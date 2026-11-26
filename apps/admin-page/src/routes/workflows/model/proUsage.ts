import type { WorkflowNode } from '@yaymatik/types';

import { templatePaths } from '../steps/core/template';

/** A Pro field, also through a relation (`order.customer.total_spent`). */
const proField = (path: string, fields: string[]) =>
  fields.some((field) => path === field || path.endsWith(`.${field}`));

const texts = (value: unknown): string[] =>
  typeof value === 'string'
    ? value.includes('{{')
      ? [value]
      : []
    : value && typeof value === 'object'
      ? Object.values(value).flatMap(texts)
      : [];

// Same as PHP `ProUsage::node()`: a Pro step, a rule on a Pro field, a group, a comparison with another field, or a
// Pro merge tag, while `proMissing` names them; always false while YayMatik Pro is active.

/** Whether a step needs YayMatik Pro while it isn't here. */
export function needsPro(node: Pick<WorkflowNode, 'data'>): boolean {
  const { steps, fields, features } = window.yayMatikMeta.automation.proMissing;
  if (!steps.length && !fields.length && !features.length) return false;
  const { block, config } = node.data;
  if (block && steps.includes(block)) return true;
  if (block === 'condition') {
    const items = Array.isArray(config.rules) ? (config.rules as Record<string, unknown>[]) : [];
    for (const item of items) {
      const group = Array.isArray(item?.rules);
      if (group && features.includes('groups')) return true;
      for (const rule of (group ? item.rules : [item]) as Record<string, unknown>[]) {
        const variable = rule?.valueType === 'variable';
        if (variable && features.includes('variables')) return true;
        const keys = [String(rule?.field ?? ''), variable ? String(rule?.value ?? '') : ''];
        if (keys.some((key) => key && proField(key, fields))) return true;
      }
    }
  }
  return texts(config).some((text) => templatePaths(text).some((path) => proField(path, fields)));
}
