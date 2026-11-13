import type { RunDetail } from '@yaymatik/types';
import { describe, expect, it } from 'vitest';

import { stepResult } from './runResults';

type Row = RunDetail['steps'][number];

const row = (over: Partial<Row>): Row => ({
  id: '1',
  runId: '9',
  stepId: 'n1',
  iteration: 0,
  status: 'completed',
  input: {},
  output: {},
  error: null,
  attempt: 1,
  startedAt: '2026-10-09T15:00:00+00:00',
  finishedAt: '2026-10-09T15:00:01+00:00',
  stepType: null,
  ...over,
});

const run = { waitUntil: null, triggerId: 'order_created' };

describe('stepResult', () => {
  it('reads a condition as Yes / No', () => {
    expect(stepResult(row({ stepType: 'condition', output: { result: 'yes' } }), run)).toBe('Yes');
    expect(stepResult(row({ stepType: 'condition', output: { result: 'no' } }), run)).toBe('No');
  });

  it('says until when a waiting wait continues, and when a finished one resumed', () => {
    const wait = { stepType: 'wait' as const, input: { delay: { value: 1, unit: 'days' } } };
    expect(
      stepResult(row({ ...wait, status: 'waiting', finishedAt: null }), {
        ...run,
        waitUntil: '2026-10-10T15:00:00+00:00',
      }),
    ).toMatch(/^until Oct 10(, 2026)? at 3:00/);
    expect(stepResult(row({ ...wait, finishedAt: '2026-10-10T15:00:00+00:00' }), run)).toMatch(
      /^Wait 1 day, resumed Oct 10(, 2026)? at 3:00/,
    );
  });

  it('names who an email went to, the coupon code and the new order status', () => {
    expect(stepResult(row({ stepType: 'send_email', output: { to: 'a@b.com' } }), run)).toBe('Sent to a@b.com');
    expect(stepResult(row({ stepType: 'send_internal_email', output: { to: ['a@b.com', 'c@d.com'] } }), run)).toBe(
      'Sent to a@b.com, c@d.com',
    );
    expect(stepResult(row({ stepType: 'generate_coupon', output: { code: 'YAY-7K2P' } }), run)).toBe('YAY-7K2P');
    expect(
      stepResult(
        row({ stepType: 'change_order_status', input: { status: 'completed' }, output: { to: 'completed' } }),
        run,
      ),
    ).toBe('Set to Completed');
  });

  it('fills another plugin’s result line from the step’s output, then its config', () => {
    const steps = window.yayMatikMeta.automation.steps;
    const stepType = 'acme_add_points' as Row['stepType'];
    steps[stepType!] = {
      ...steps.add_order_note!,
      plugin: 'acme',
      summary: 'Add %points%',
      result: 'New balance: %balance% (+%points%)',
    };
    try {
      expect(stepResult(row({ stepType, input: { points: 10 }, output: { balance: 35 } }), run)).toBe(
        'New balance: 35 (+10)',
      );
      expect(stepResult(row({ stepType, output: { balance: [1, 2] } }), run)).toBe('New balance: 1, 2 (+)');
      steps[stepType!] = { ...steps[stepType!]!, result: '' };
      // No result line: the card summary (none here: the step isn't in this test's registry).
      expect(stepResult(row({ stepType, input: { points: 10 } }), run)).toBe('');
    } finally {
      delete steps[stepType!];
    }
  });

  it('says why a step was skipped, failed or cancelled', () => {
    for (const status of ['skipped', 'failed', 'cancelled'] as const) {
      expect(stepResult(row({ stepType: 'send_email', status, error: 'The customer unsubscribed.' }), run)).toBe(
        'The customer unsubscribed.',
      );
    }
  });

  it('says whether a custom field changed, without its value', () => {
    const meta = (changed: boolean) =>
      stepResult(row({ stepType: 'update_order_meta', output: { key: 'gift_wrap', changed } }), run);
    expect(meta(true)).toBe('Custom field gift_wrap updated');
    expect(meta(false)).toBe('Custom field gift_wrap unchanged');
    expect(stepResult(row({ stepType: 'update_customer_meta', output: { key: 'vip', changed: true } }), run)).toBe(
      'Custom field vip updated',
    );
  });

  it('names the role a step gave or took, by its name', () => {
    const role = (mode: string, from = 'Customer', to = 'Customer, Wholesale') =>
      stepResult(
        row({ stepType: 'change_customer_role', input: { mode, role: 'customer' }, output: { from, to } }),
        run,
      );
    expect(role('add')).toBe('Added Customer');
    expect(role('remove')).toBe('Removed Customer');
    expect(role('replace')).toBe('Replaced roles with Customer');
    expect(role('add', 'Customer', 'Customer')).toBe('Roles unchanged');
    expect(
      stepResult(
        row({ stepType: 'change_customer_role', input: { mode: 'add', role: 'gone' }, output: { to: 'gone' } }),
        run,
      ),
    ).toBe('Added gone');
  });

  it('names the WooCommerce email it resent, never the address', () => {
    expect(stepResult(row({ stepType: 'resend_wc_email', output: { email: 'customer_completed_order' } }), run)).toBe(
      'Sent Completed order',
    );
  });

  it('falls back to the card summary, and to nothing for a removed step', () => {
    expect(stepResult(row({ stepType: 'add_order_note', input: { note: 'Thanks', type: 'private' } }), run)).not.toBe(
      '',
    );
    expect(stepResult(row({ stepType: null }), run)).toBe('');
  });
});
