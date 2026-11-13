import { __, sprintf } from '@wordpress/i18n';

import type { DataFieldType, DataItemMeta } from '@/types/localize';
import { messages } from '../../model/messages';
import { templateErrors, templatePaths } from './template';

/** Data items and their fields, from PHP `DataRegistry`. */

export type DataField = DataItemMeta['fields'][number];

const meta = window.yayMatikMeta.automation;

export const dataItems = meta.data;

/** Items every workflow has, whatever the trigger supplies. */
export const alwaysAvailable = meta.always;

/**
 * Trigger facts (`trigger.points`): the first trigger with a key defines it, as in PHP `DataRegistry::field()`.
 * A trigger whose plugin is off keeps its facts' keys only, read as text.
 */
const facts = [
  ...Object.values(meta.inactiveSteps).flatMap((step) =>
    step.facts.map((key) => ({
      key: `trigger.${key}`,
      label: key,
      type: 'string' as const,
      valueType: 'string',
      conditional: false,
    })),
  ),
  ...Object.values(meta.steps).flatMap((step) => step?.facts ?? []),
].reverse();

const fields = new Map(
  [...facts, ...dataItems.flatMap((item) => item.fields)].map((field) => [field.key, field] as const),
);

export const fieldByKey = (key: string): DataField | undefined => fields.get(key);

/** `order.total` → `order`. */
export const itemOf = (key: string) => key.split('.')[0];

export const itemLabel = (id: string) =>
  dataItems.find((item) => item.id === id)?.label ?? (id === 'trigger' ? __('Trigger', 'yaymatik') : id);

/** Operator ids for a field type, from PHP `Operators::BY_TYPE`. */
export const operatorsFor = (type: DataFieldType) => meta.operators[type];

/** Operators that compare against nothing. Mirrors PHP `Operators::needs_value()`. */
export const needsValue = (operator: string) =>
  !['is_empty', 'is_not_empty', 'is_true', 'is_false', 'is_set', 'is_not_set'].includes(operator);

/** Operators whose value is a list. Mirrors PHP `Operators::takes_list()`. */
export const takesList = (operator: string) =>
  ['matches_any', 'matches_all', 'matches_none', 'is_any_of'].includes(operator);

/** Operators whose value is an amount and a unit. Mirrors PHP `Operators::is_relative()`. */
export const isRelative = (operator: string) => ['in_last', 'not_in_last'].includes(operator);

/** How each operator reads in the condition's rules and summary ("is greater than"), from PHP `Operators::labels()`. */
export const operatorLabels = meta.operatorLabels;

/** Merge tag for a key, as inserted by "Insert variable". */
export const variableTag = (key: string) => `{{ ${key} }}`;

/** A path without the optional `trigger.` prefix, split: `trigger.order.total` → `['order', 'total']`. */
export const pathSegments = (path: string) => {
  const segments = path.split('.');
  return segments[0] === 'trigger' ? segments.slice(1) : segments;
};

/** A step output's path: `steps.<step id>.<key>`. Which steps exist is up to the graph. */
export const isStepPath = (segments: string[]) => segments[0] === 'steps';

/**
 * What a merge-tag path reads, following relations: a data field (`order.customer.first_name` →
 * `customer.first_name`), a relation itself (`order.customer`), or undefined. Mirrors PHP
 * `Validator::has_field()`.
 */
function resolvePath(path: string) {
  const fact = path.startsWith('trigger.') ? fieldByKey(path) : undefined;
  if (fact) return { field: fact };
  const [first, ...rest] = pathSegments(path);
  let item = dataItems.find((i) => i.id === first);
  for (const [i, segment] of rest.entries()) {
    if (!item) return undefined;
    const key = `${item.id}.${segment}`;
    const relation = item.relations.find((r) => r.key === key);
    if (i === rest.length - 1) {
      const field = fieldByKey(key);
      if (field) return field.mergeTag === false ? undefined : { field };
      return relation && { relation };
    }
    item = relation && dataItems.find((target) => target.id === relation.item);
  }
  return undefined;
}

/** The data field a merge-tag path reads, following relations (`order.customer.first_name` → `customer.first_name`). */
export const fieldByPath = (path: string): DataField | undefined => resolvePath(path)?.field;

/** The name of what a merge-tag path reads ("Customer first name"), or undefined for a path nothing defines. */
export const pathLabel = (path: string): string | undefined => {
  const found = resolvePath(path);
  return found?.field?.label ?? found?.relation?.label;
};

/**
 * The first problem with the merge tags in these texts, or null: a malformed tag or unknown filter,
 * then a path nothing defines. Per text, like PHP `Validator::tag_error()`; whether the step can reach
 * the data is the workflow's check (`missing_data`).
 */
export function tagError(texts: string[]) {
  for (const text of texts) {
    const error = templateErrors(text)[0];
    if (error) return error;
    const unknown = templatePaths(text).find((path) => {
      const segments = pathSegments(path);
      if (isStepPath(segments)) return segments.length !== 3;
      return !resolvePath(path);
    });
    if (unknown) return sprintf(messages.unknownVariable, variableTag(unknown));
  }
  return null;
}
