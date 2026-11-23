import { expect, it, vi } from 'vitest';

import { steps } from '@/routes/workflows/steps';

const count = (kind: string) => Object.values(steps).filter((step) => step.kind === kind).length;

it('adds 5 triggers and 5 actions to the free edition’s 9 and 6', () => {
  expect([count('trigger'), count('action')]).toEqual([14, 11]);
});

it('draws the customer milestones with the icons the pages mock draws', () => {
  const { steps: catalog } = window.yayMatikMeta.automation;
  expect([catalog.customer_order_count_reaches?.icon, catalog.customer_total_spend_reaches?.icon]).toEqual([
    'activity',
    'heart-pulse',
  ]);
});

it('builds no Pro step without a license, so a catalog without them still loads', async () => {
  const { automation } = window.yayMatikMeta;
  const all = automation.steps;
  const pro = [
    'customer_inactive',
    'customer_order_count_reaches',
    'customer_total_spend_reaches',
    'user_logged_in',
    'customer_logged_in',
    'wait_until',
  ];
  automation.steps = Object.fromEntries(Object.entries(all).filter(([id]) => !pro.includes(id)));
  window.yayMatikPro.licensed = false;
  vi.resetModules();
  try {
    const { steps: unlicensed } = await import('./steps');
    expect(unlicensed).toEqual([]);
  } finally {
    automation.steps = all;
    window.yayMatikPro.licensed = true;
  }
});
