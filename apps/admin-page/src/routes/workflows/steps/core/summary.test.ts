import { describe, expect, it } from 'vitest';

import type { StepFieldMeta } from '@/types/localize';
import { steps } from '..';
import { PLACEHOLDER, plainSummary, value } from './marks';
import { renderSummary, variableNames } from './summary';

const plain = plainSummary;

describe('renderSummary', () => {
  const status: StepFieldMeta = {
    key: 'status',
    type: 'select',
    label: 'Status',
    options: [{ value: 'completed', label: 'Completed' }],
  };

  it('bolds a value and shows its option label', () => {
    expect(renderSummary('Change to %status%', [status], { status: 'completed' })).toBe(
      `Change to ${value('Completed')}`,
    );
  });

  it('shows "Not set" for a blank value, and the field placeholder when it has one', () => {
    // A select's placeholder is a prompt ("Choose a status"), not a value.
    const prompt = { ...status, placeholder: 'Choose a status' };
    expect(renderSummary('Change to %status%', [prompt], { status: '' })).toBe(`Change to ${PLACEHOLDER}`);
    expect(plain(PLACEHOLDER)).toBe('Not set');
    const from = { ...status, type: 'multiselect', placeholder: 'Any status' } satisfies StepFieldMeta;
    expect(renderSummary('From %status%', [from], { status: [] })).toBe(`From ${value('Any status')}`);
  });

  it('joins lists with "or" and collapses line breaks', () => {
    const multi = {
      ...status,
      type: 'multiselect',
      options: [...status.options!, { value: 'x', label: 'X' }],
    } satisfies StepFieldMeta;
    expect(plain(renderSummary('%status%', [multi], { status: ['completed', 'x', 'y'] }))).toBe('Completed, X or y');
    const note: StepFieldMeta = { key: 'note', type: 'textarea', label: 'Note' };
    expect(plain(renderSummary('%note%', [note], { note: 'Thanks\n\nfor  ordering ' }))).toBe('Thanks for ordering');
  });

  it('lets the step write a placeholder, and keeps unknown ones as they are', () => {
    expect(renderSummary('%rules% %nope%', [], {}, { rules: () => 'A and B' })).toBe('A and B %nope%');
    expect(renderSummary('%rules%', [], {}, { rules: () => '' })).toBe(PLACEHOLDER);
  });
});

describe('variableNames', () => {
  it('names known paths, through relations and filters; leaves the rest as typed', () => {
    expect(variableNames('Order {{ order.number }} for {{ order.customer.first_name | upper }}')).toBe(
      'Order Order number for Customer first name',
    );
    expect(variableNames('{{ order.customer }} {{ trigger.order.total }}')).toBe('Customer Order total');
    expect(variableNames('{{ order.nope }} {{ steps.n1.code }} {{ bad tag }}')).toBe(
      '{{ order.nope }} {{ steps.n1.code }} {{ bad tag }}',
    );
  });

  it('applies to fields that take merge tags only', () => {
    const note: StepFieldMeta = { key: 'note', type: 'textarea', label: 'Note', variables: true };
    expect(plain(renderSummary('%note%', [note], { note: 'New order {{ order.number }}' }))).toBe(
      'New order Order number',
    );
    const key: StepFieldMeta = { key: 'key', type: 'text', label: 'Key' };
    expect(plain(renderSummary('%key%', [key], { key: '{{ order.number }}' }))).toBe('{{ order.number }}');
  });
});

describe('plainSummary', () => {
  it('removes the marks and keeps the words', () => {
    const text = `Set ${value('_note')} to ${PLACEHOLDER}`;
    expect(text).not.toBe('Set _note to Not set');
    expect(plainSummary(text)).toBe('Set _note to Not set');
    expect(plainSummary('No marks')).toBe('No marks');
  });
});

describe('catalog summaries', () => {
  it('trigger: blank statuses read as "Any status"', () => {
    const summary = steps.order_status_changed.summary({ from: [], to: ['completed', 'processing'] });
    expect(plain(summary)).toBe('Any status → Completed or Processing');
  });

  it('order note names its type', () => {
    expect(plain(steps.add_order_note.summary({ type: 'customer', note: 'Shipped' }))).toBe(
      'Note to customer: Shipped',
    );
  });

  it('internal email falls back to the site admin address', () => {
    expect(plain(steps.send_internal_email.summary({ recipients: '', subject: 'New order' }))).toBe(
      'Send to admin@example.test',
    );
  });

  it('condition: rules on one line, or "Not set" with none', () => {
    const rule = { field: 'order.total', operator: 'greater_than', value: '500', valueType: 'value' };
    expect(plain(steps.condition.summary({ match: 'any', rules: [rule, rule] }))).toBe(
      'Order total is greater than 500 or Order total is greater than 500',
    );
    expect(steps.condition.summary({ match: 'all', rules: [] })).toBe(PLACEHOLDER);
  });

  it('a built step takes its label and icon from the catalog', () => {
    const meta = window.yayAutomationMeta.automation.steps.add_order_note!;
    expect(steps.add_order_note.label).toBe(meta.label);
    expect(steps.add_order_note.icon).toBeDefined();
  });
});
