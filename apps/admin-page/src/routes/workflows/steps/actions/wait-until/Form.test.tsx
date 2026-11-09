import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { steps } from '../..';

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let container: HTMLDivElement;
let root: Root;

beforeEach(() => {
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

const input = () => document.getElementById('step-field-time') as HTMLInputElement;
const type = (text: string) =>
  act(() => {
    Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')!.set!.call(input(), text);
    input().dispatchEvent(new Event('input', { bubbles: true }));
  });

describe('Wait until form', () => {
  it('draws the time as a time input by the minute, with the help line under it', () => {
    const Form = steps.wait_until.Form!;
    act(() => root.render(<Form config={{ time: '11:10', weekdays: [] }} onChange={() => {}} />));
    expect(input().type).toBe('time');
    expect(input().step).toBe('60');
    expect(input().value).toBe('11:10');
    expect(container.textContent).toContain('Runs resume when WordPress next runs its scheduled tasks');
    expect(container.textContent).toContain('Past 11:10 am, it waits for the next chosen day.');
  });

  it('writes the typed time as HH:MM, and blank when cleared', () => {
    const Form = steps.wait_until.Form!;
    const onChange = vi.fn();
    act(() => root.render(<Form config={{ time: '09:00', weekdays: ['1'] }} onChange={onChange} />));
    type('11:07');
    expect(onChange).toHaveBeenLastCalledWith({ time: '11:07', weekdays: ['1'] });
    type('');
    expect(onChange).toHaveBeenLastCalledWith({ time: '', weekdays: ['1'] });
    expect(container.textContent).toContain('Enter a time, e.g. 9:15 am.');
  });
});
