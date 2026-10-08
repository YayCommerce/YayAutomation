import { describe, expect, it } from 'vitest';

import { structureErrors } from '../model/structure';
import { validateWorkflow } from '../model/validation';
import { templates } from './index';

describe('templates', () => {
  it('has templates with unique ids', () => {
    expect(templates.length).toBeGreaterThan(0);
    expect(new Set(templates.map((template) => template.id)).size).toBe(templates.length);
  });

  it.each(templates.map((template) => [template.id, template] as const))(
    '%s saves and can be activated as is',
    (_id, template) => {
      expect(structureErrors(template.graph)).toEqual([]);
      expect(validateWorkflow({ name: template.name, graph: template.graph })).toEqual([]);
    },
  );
});
