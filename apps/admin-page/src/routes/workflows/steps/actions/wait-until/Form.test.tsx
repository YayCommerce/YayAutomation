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

  it('keeps the time control 160px inside a wrapper the field can size', () => {
    const Form = steps.wait_until.Form!;
    act(() => root.render(<Form config={{ time: '11:10', weekdays: [] }} onChange={() => {}} />));
    const group = input().closest('[data-slot="input-group"]')!;
    expect(group.classList).toContain('w-40');
    expect(group.parentElement!.parentElement!.dataset.slot).toBe('field');
  });

  it('opens the browser picker from the clock button, and survives a browser without one', () => {
    const Form = steps.wait_until.Form!;
    act(() => root.render(<Form config={{ time: '11:10', weekdays: [] }} onChange={() => {}} />));
    const button = container.querySelector('button[aria-label="Choose a time"]') as HTMLButtonElement;
    const showPicker = vi.fn();
    input().showPicker = showPicker;
    act(() => button.click());
    expect(showPicker).toHaveBeenCalledOnce();
    expect(document.activeElement).toBe(input());
    input().showPicker = () => {
      throw new DOMException('Not allowed', 'NotAllowedError');
    };
    expect(() => act(() => button.click())).not.toThrow();
  });

  it('shows the store-time hint with a clock, and only the zone while the time is blank', () => {
    const Form = steps.wait_until.Form!;
    act(() => root.render(<Form config={{ time: '', weekdays: [] }} onChange={() => {}} />));
    const hint = [...container.querySelectorAll('p')].find((p) => p.textContent?.startsWith('Store time'))!;
    expect(hint.textContent).toBe('Store time (UTC).');
    expect(hint.querySelector('svg')!.classList).toContain('lucide-clock');
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
