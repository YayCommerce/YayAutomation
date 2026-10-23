import { describe, expect, it } from 'vitest';

import { runStatusText } from './RunStatus';

describe('runStatusText', () => {
  it('shows a queued run as Running', () => {
    expect(runStatusText({ status: 'queued', waitUntil: null })).toBe('Running');
    expect(runStatusText({ status: 'running', waitUntil: null })).toBe('Running');
  });

  it('says until when a waiting run continues', () => {
    expect(runStatusText({ status: 'waiting', waitUntil: '2026-10-09T15:00:00+00:00' })).toMatch(
      /^Waiting until Oct 9(, 2026)? at 3:00/,
    );
  });

  it('labels every ended status', () => {
    expect(
      (['completed', 'failed', 'cancelled', 'skipped'] as const).map((status) =>
        runStatusText({ status, waitUntil: null }),
      ),
    ).toEqual(['Completed', 'Failed', 'Cancelled', 'Skipped']);
  });
});
