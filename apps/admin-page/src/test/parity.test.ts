/**
 * PHP ↔ TS parity over the fixture (`src/test/fixtures/meta.json`, PHP `Definition\Catalog`).
 * PHP is the one source for steps, plugins, data and operators; these checks catch the TS side
 * drifting from it. Catalog change → `./run.sh meta-fixture`, then run this test.
 */
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { StepId, WorkflowPlugin } from '@yaymatik/types';
import { icons } from 'lucide-react';
import { describe, expect, it } from 'vitest';
import { sprintf } from '@wordpress/i18n';

import type { DataFieldType } from '@/types/localize';
import { messages } from '../routes/workflows/model/messages';
import { steps } from '../routes/workflows/steps';
import { fieldByPath, needsValue, operatorLabels } from '../routes/workflows/steps/core/data';
import { stepIcons } from '../routes/workflows/steps/core/icons';
import { automationMeta, isPendingStep } from '../routes/workflows/steps/core/pending';
import { templatePaths } from '../routes/workflows/steps/core/template';
import { isComingSoon, templates } from '../routes/workflows/templates';

const php = window.yayMatikMeta.automation;
const phpStepIds = Object.keys(php.steps) as StepId[];
const tsStepIds = Object.keys(steps) as StepId[];
const phpDataKeys = new Set(php.data.flatMap((item) => item.fields.map((field) => field.key)));
const knownDataKeys = new Set(automationMeta.data.flatMap((item) => item.fields.map((field) => field.key)));
const phpOperators = new Set(Object.values(php.operators).flat());

/** Every TS `DataFieldType`; the `satisfies` keeps it in step with the type. */
const dataFieldTypes = { number: true, string: true, enum: true, date: true, boolean: true } satisfies Record<
  DataFieldType,
  true
>;
const workflowPlugins = { woocommerce: true, yaymatik: true } satisfies Record<WorkflowPlugin, true>;

/** PHP `Type::to_string()`: a scalar name, `entity<id>` or `list<…>`. */
const TYPE_NAME = /^(string|number|boolean|date|datetime|money|enum|object|entity<[a-z_]+>)$/;
const isTypeName = (type: string): boolean => {
  const list = /^list<(.+)>$/.exec(type);
  return list ? isTypeName(list[1]) : TYPE_NAME.test(type);
};

/** `mail` → `Mail`, `arrow-right-left` → `ArrowRightLeft`, as lucide-react names its icons. */
const lucideName = (name: string) => name.replace(/(^|-)([a-z0-9])/g, (_m, _dash, char: string) => char.toUpperCase());

const strings = (value: unknown): string[] =>
  typeof value === 'string' ? [value] : value && typeof value === 'object' ? Object.values(value).flatMap(strings) : [];

describe('PHP ↔ TS parity', () => {
  it('every TS step is in the PHP catalog or listed as pending', () => {
    expect(phpStepIds).toHaveLength(9);
    expect(tsStepIds.filter((id) => !php.steps[id] && !isPendingStep(id))).toEqual([]);
  });

  it('every PHP step has a TS step of the same kind', () => {
    for (const id of phpStepIds) {
      expect(steps[id], id).toBeDefined();
      expect(steps[id].kind, id).toBe(php.steps[id]!.kind);
    }
  });

  it('a PHP step stores exactly the keys its TS defaults have', () => {
    for (const id of phpStepIds) {
      const stored = php.steps[id]!.fields.filter((field) => field.type !== 'notice').map((field) => field.key);
      expect(stored.sort(), id).toEqual(Object.keys(steps[id].defaults()).sort());
    }
  });

  it('step icons are lucide icons', () => {
    for (const id of phpStepIds) {
      expect(icons, `${id}: ${php.steps[id]!.icon}`).toHaveProperty(lucideName(php.steps[id]!.icon));
    }
  });

  it('the TS icon map has exactly the names in PHP StepIcons::NAMES', () => {
    const php = readFileSync(resolve(__dirname, '../../../../includes/Workflow/Steps/Core/StepIcons.php'), 'utf8');
    const list = /NAMES = \[([\s\S]*?)\];/.exec(php)![1];
    const names = [...list.matchAll(/'([a-z0-9-]+)'/g)].map((m) => m[1]);
    expect(names.length).toBeGreaterThan(0);
    expect(Object.keys(stepIcons)).toEqual(names);
  });

  it('plugins match: PHP sends every plugin TS knows, each with a currentColor logo', () => {
    expect(php.plugins.map((plugin) => plugin.id).sort()).toEqual(Object.keys(workflowPlugins).sort());
    for (const plugin of php.plugins) {
      expect(plugin.logo, plugin.id).toMatch(/^<svg [^>]*viewBox="0 0 24 24"[^>]*stroke="currentColor"/);
    }
    for (const meta of Object.values(automationMeta.steps)) {
      if (meta.plugin) expect(workflowPlugins).toHaveProperty(meta.plugin);
    }
  });

  it('data fields have a TS field type and a known value type', () => {
    for (const item of php.data) {
      for (const field of item.fields) {
        expect(dataFieldTypes, field.key).toHaveProperty(field.type);
        expect(isTypeName(field.valueType ?? ''), `${field.key}: ${field.valueType}`).toBe(true);
      }
    }
  });

  it('step outputs use the same type names as data fields', () => {
    for (const id of phpStepIds) {
      for (const output of php.steps[id]!.outputs) {
        expect(isTypeName(output.type), `${id}.${output.key}: ${output.type}`).toBe(true);
      }
    }
  });

  it('operators: PHP labels each operator once, and TS reads those labels', () => {
    expect(Object.keys(php.operatorLabels).sort()).toEqual([...phpOperators].sort());
    expect(Object.values(php.operatorLabels).every((label) => label.trim() !== '')).toBe(true);
    expect(operatorLabels).toBe(php.operatorLabels);
    expect(Object.keys(php.operators).sort()).toEqual(Object.keys(dataFieldTypes).sort());
    // TS `needsValue()` mirrors PHP: the no-value operators are the boolean and empty checks.
    expect([...phpOperators].filter((op) => !needsValue(op)).sort()).toEqual(
      ['is_empty', 'is_false', 'is_not_empty', 'is_true'].sort(),
    );
  });

  it('shared messages are the same in PHP and TS', () => {
    expect(messages).toEqual(php.messages);
  });

  it("a blank required field gives PHP's message, with the step's label", () => {
    let checked = 0;
    for (const id of phpStepIds) {
      const meta = php.steps[id]!;
      for (const field of meta.fields.filter((f) => f.requiredMessage)) {
        // The other required fields filled in, so this one's message is the first.
        const filled = Object.fromEntries(
          meta.fields.filter((f) => f.requiredMessage && f.key !== field.key).map((f) => [f.key, 'a@x.com']),
        );
        const config = { ...steps[id].defaults(), ...filled, [field.key]: '' };
        expect(steps[id].firstError(config), `${id}.${field.key}`).toBe(
          sprintf(field.requiredMessage as '%s', meta.label),
        );
        checked++;
      }
    }
    expect(checked).toBe(8);
  });

  describe.each(templates.map((template) => [template.id, template] as const))('template %s', (_id, template) => {
    const comingSoon = isComingSoon(template.graph);
    // A template that can be installed today uses only what PHP has; one coming soon may use pending entries.
    const dataKeys = comingSoon ? knownDataKeys : phpDataKeys;

    it('uses only step ids in the catalog', () => {
      for (const { data } of template.graph.nodes) {
        if (!data.block) continue;
        expect(Boolean(php.steps[data.block]) || (comingSoon && isPendingStep(data.block)), data.block).toBe(true);
      }
    });

    it('condition keys and merge tags resolve to known data paths', () => {
      for (const { data } of template.graph.nodes) {
        if (data.block === 'condition') {
          const rules = (data.config as { rules: { field: string; value: string; valueType?: string }[] }).rules;
          for (const rule of rules) {
            expect(dataKeys.has(rule.field), rule.field).toBe(true);
            if (rule.valueType === 'variable') expect(dataKeys.has(rule.value), rule.value).toBe(true);
          }
        }
        for (const path of strings(data.config).flatMap(templatePaths)) {
          // `steps.<node id>.<output>`: an output PHP declares for that node's step.
          const [root, nodeId, output] = path.split('.');
          if (root === 'steps') {
            const block = template.graph.nodes.find((node) => node.id === nodeId)?.data.block;
            const outputs = (block && php.steps[block]?.outputs) || [];
            expect(outputs.some((o) => o.key === output), path).toBe(true);
            continue;
          }
          expect(dataKeys.has(fieldByPath(path)?.key ?? ''), path).toBe(true);
        }
      }
    });
  });
});
