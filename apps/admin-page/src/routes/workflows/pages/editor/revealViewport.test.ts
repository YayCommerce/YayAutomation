import { describe, expect, it } from 'vitest';

import { revealViewport } from './revealViewport';

// A 1366×768-like canvas with a 480px panel on the left.
const area = { panelInset: 480, width: 1100, height: 700 };
const node = (x: number, y: number) => ({ x, y, w: 240, h: 80 });

describe('revealViewport', () => {
  it('leaves a node that is in view where it is', () => {
    expect(revealViewport(node(600, 300), { x: 0, y: 0, zoom: 1 }, area)).toBeNull();
  });

  it('moves a node hidden only by the panel sideways, not up or down', () => {
    const moved = revealViewport(node(100, 300), { x: 0, y: -50, zoom: 1 }, area);

    expect(moved).toEqual({ x: 790 - 220, y: -50, zoom: 1 });
  });

  it('moves a node only below the fold up, not sideways', () => {
    const moved = revealViewport(node(600, 900), { x: 10, y: 0, zoom: 1 }, area);

    expect(moved).toEqual({ x: 10, y: 350 - 940, zoom: 1 });
  });

  it('centres a node off on both axes, as before', () => {
    const viewport = { x: 0, y: 0, zoom: 0.5 };
    const moved = revealViewport(node(-800, 2000), viewport, area);
    const centerX = area.panelInset + (area.width - area.panelInset) / 2;

    expect(moved).toEqual({
      x: centerX - (-800 + 120) * 0.5,
      y: area.height / 2 - (2000 + 40) * 0.5,
      zoom: 0.5,
    });
  });
});
