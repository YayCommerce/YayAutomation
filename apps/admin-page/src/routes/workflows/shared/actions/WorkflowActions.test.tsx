import { act, type ReactElement } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';

import { cancelledRunsText, DeactivateDialog, UpdateDialog } from './WorkflowActions';

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

const confirm = { open: true, onOpenChange: () => {}, onConfirm: () => {}, pending: false };

/** The open dialog's description text. */
function description(dialog: ReactElement) {
  act(() => root.render(dialog));
  return document.body.querySelector('[role="dialog"] p, [role="dialog"] [id$="description"]')?.textContent;
}

describe('Deactivate and Update dialogs', () => {
  it('say how many waiting runs one Deactivate cancels, and nothing about them when none', () => {
    expect(description(<DeactivateDialog {...confirm} name="Thanks" waitingRuns={2} />)).toBe(
      'New events won’t start it. Runs under way stop before their next step. You can activate it again at any time. 2 waiting runs will be cancelled.',
    );
    expect(description(<DeactivateDialog {...confirm} name="Thanks" waitingRuns={0} />)).toBe(
      'New events won’t start it. Runs under way stop before their next step. You can activate it again at any time.',
    );
  });

  it('use "them" and the sum for several workflows', () => {
    expect(description(<DeactivateDialog {...confirm} count={3} waitingRuns={1} />)).toBe(
      'New events won’t start them. Runs under way stop before their next step. You can activate them again at any time. 1 waiting run will be cancelled.',
    );
  });

  it('put the count between the Update sentences', () => {
    expect(description(<UpdateDialog {...confirm} waitingRuns={4} />)).toBe(
      'It’s active, so your draft changes take effect right away. 4 waiting runs will be cancelled. Runs under way finish on the version they started on.',
    );
    expect(description(<UpdateDialog {...confirm} waitingRuns={0} />)).toBe(
      'It’s active, so your draft changes take effect right away. Runs under way finish on the version they started on.',
    );
  });

  it('give the toast the real count only when runs were cancelled', () => {
    expect(cancelledRunsText(1)).toBe('1 waiting run was cancelled.');
    expect(cancelledRunsText(0)).toBeUndefined();
    expect(cancelledRunsText(undefined)).toBeUndefined();
  });
});
