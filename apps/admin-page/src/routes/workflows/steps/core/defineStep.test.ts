import { describe, expect, it } from 'vitest';

import { steps } from '..';

describe('normalize', () => {
  it('keeps only the fields of a built step, like PHP sanitize_all()', () => {
    expect(steps.order_created.normalize({ x: 1 })).toEqual({});
    expect(steps.update_order_meta.normalize({ key: '_vip', junk: 1 })).toEqual({ key: '_vip', value: '' });
  });

  it('stores status slugs like PHP OrderData::status_slug()', () => {
    const config = { from: ['wc-pending', 'pending', 'Processing', '', 3, ['x']], to: 'completed', only_checkout: '1' };
    expect(steps.order_status_changed.normalize(config)).toEqual({
      from: ['pending', 'processing'],
      to: [],
      only_checkout: true,
    });
  });

  it('starts Send internal email with a sample subject and message', () => {
    const config = steps.send_internal_email.defaults();
    expect(config).toMatchObject({ recipients: '' });
    expect(steps.send_internal_email.firstError(config)).toBeNull();
  });
});
