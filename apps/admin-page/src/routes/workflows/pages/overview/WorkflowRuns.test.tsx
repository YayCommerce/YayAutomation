import { act } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { WorkflowRuns } from './WorkflowRuns';

vi.mock('../../api/workflow.query', () => ({
  useWorkflowRunsQuery: () => ({
    data: { runs: [], total: 0, pages: 0 },
    error: null,
    isPending: false,
    isPlaceholderData: false,
  }),
}));

(globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }).IS_REACT_ACT_ENVIRONMENT = true;

let container: HTMLDivElement;
let root: Root;

beforeEach(async () => {
  container = document.createElement('div');
  document.body.append(container);
  root = createRoot(container);
  await act(async () => root.render(<WorkflowRuns workflowId="7" onOpen={() => {}} />));
});

afterEach(() => {
  act(() => root.unmount());
  container.remove();
});

describe('WorkflowRuns empty state', () => {
  it('says no runs yet, with no docs link', () => {
    expect(container.textContent).toContain('No runs yet');
    expect(container.querySelectorAll('a')).toHaveLength(0);
    expect(container.textContent).not.toContain('Learn more');
  });
});
