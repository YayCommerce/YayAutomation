import { expect, it } from 'vitest';

import { steps } from '@/routes/workflows/steps';

const count = (kind: string) => Object.values(steps).filter((step) => step.kind === kind).length;

it('picks from the 9 triggers and 6 actions of the free edition', () => {
  expect([count('trigger'), count('action')]).toEqual([9, 6]);
});
