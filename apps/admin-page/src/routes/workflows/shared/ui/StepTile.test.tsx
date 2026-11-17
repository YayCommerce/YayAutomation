import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { describe, expect, it } from 'vitest';

import { StepEyebrow } from './StepTile';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

describe('StepEyebrow', () => {
  it('shows the kind only for a plugin that is off (not in the plugin list)', () => {
    const container = document.createElement('div');
    const root = createRoot(container);
    act(() => root.render(<StepEyebrow kind="action" kindLabel="Action" plugin={'acme' as never} />));
    expect(container.textContent).toBe('Action');
    act(() => root.unmount());
  });
});
