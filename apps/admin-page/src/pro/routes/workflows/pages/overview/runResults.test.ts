import type { RunDetail } from '@yaymatik/types';
import { describe, expect, it } from 'vitest';

import { stepResult } from '@/routes/workflows/pages/overview/runResults';

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

describe('stepResult of Pro steps', () => {
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

  it('says until when a waiting wait-until continues, and when it ended', () => {
    const wait = { stepType: 'wait_until' as const, input: { time: '09:00', weekdays: [] } };
    expect(
      stepResult(row({ ...wait, status: 'waiting', finishedAt: null }), {
        ...run,
        waitUntil: '2026-10-10T15:00:00+00:00',
      }),
    ).toMatch(/^until Oct 10(, 2026)? at 3:00/);
    expect(stepResult(row({ ...wait, finishedAt: '2026-10-10T15:00:00+00:00' }), run)).toMatch(
      /^Waited until Oct 10(, 2026)? at 3:00/,
    );
  });
});
