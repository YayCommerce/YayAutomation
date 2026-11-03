import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { describe, expect, it } from 'vitest';

import { customerInactiveStep as step } from '.';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

describe('Customer inactive', () => {
  it('starts at 90 days, in the Customers section', () => {
    expect(step.defaults()).toEqual({ days: 90 });
    expect(step.firstError(step.defaults())).toBeNull();
    expect(step.category).toBe('customer');
  });

  it('takes 7 to 730 days', () => {
    expect(step.firstError({ days: 7 })).toBeNull();
    expect(step.firstError({ days: 730 })).toBeNull();
    expect(step.firstError({ days: 6 })).toBe('Enter 7 or more.');
    expect(step.firstError({ days: 731 })).toBe('Enter 730 or less.');
    expect(step.firstError({ days: null })).not.toBeNull();
  });

  it('keeps whole days like PHP sanitize(), and a blank as blank', () => {
    expect(step.normalize({ days: 90.5 })).toEqual({ days: 91 });
    expect(step.normalize({ days: 90, junk: 1 })).toEqual({ days: 90 });
    expect(step.normalize({})).toEqual({ days: 90 });
    expect(step.normalize({ days: null })).toEqual({ days: null });
  });

  it('shows the field and "Checked once a day" under it', () => {
    const container = document.createElement('div');
    const root = createRoot(container);
    const Form = step.Form!;
    act(() => root.render(<Form config={{ days: 90 }} onChange={() => {}} />));

    expect(container.querySelector('input[type="number"]')?.getAttribute('value')).toBe('90');
    expect(container.textContent).toContain('Days without an order');
    expect(container.textContent).toContain('Checked once a day');
    act(() => root.unmount());
  });
});
