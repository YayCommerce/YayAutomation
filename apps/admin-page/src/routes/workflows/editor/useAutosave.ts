import { useCallback, useEffect, useRef, useState } from 'react';
import { useStore } from '@tanstack/react-form';
import type { WorkflowGraph } from '@yay-automation/types';

import type { WorkflowForm } from './useWorkflowForm';
import { useUpdateWorkflowMutation } from './workflow.query';

const DELAY_MS = 800;

/**
 * `saved`: the server has the form's graph. `pending`: a change is waiting for the debounce or
 * behind a request. `saving`: a request is out. `error`: the last request failed; nothing is retried
 * until `flush()` runs (Retry, an action, or the next edit).
 */
export type AutosaveStatus = 'saved' | 'pending' | 'saving' | 'error';

/**
 * Saves the form's graph shortly after each change. Requests go out one at a time, and a change made
 * during a request is saved right after it. The server decides where the graph goes (live when
 * inactive, the draft when active).
 */
export function useAutosave(form: WorkflowForm, workflowId: string) {
  // `mutateAsync` is stable across renders (the mutation object isn't), so `flush` is too.
  const { mutateAsync: update } = useUpdateWorkflowMutation();
  const graph = useStore(form.store, (state) => state.values.graph);

  // What the server has. Compared by identity: every edit makes a new graph object.
  const savedRef = useRef<WorkflowGraph>(graph);
  const queueRef = useRef<Promise<boolean>>(Promise.resolve(true));
  const timerRef = useRef<ReturnType<typeof setTimeout>>(undefined);
  const stoppedRef = useRef(false);
  const [status, setStatusState] = useState<AutosaveStatus>('saved');
  const [error, setError] = useState<Error | null>(null);
  // Mirrors `status` for callers that read it outside render (route blocker) and for the effect below.
  const statusRef = useRef<AutosaveStatus>('saved');
  const setStatus = useCallback((next: AutosaveStatus) => {
    statusRef.current = next;
    setStatusState(next);
  }, []);

  const saveLatest = useCallback(async () => {
    const current = form.state.values.graph;
    if (stoppedRef.current || current === savedRef.current) return true;
    setStatus('saving');
    try {
      await update({ id: workflowId, graph: current });
    } catch (caught) {
      setError(caught instanceof Error ? caught : new Error(String(caught)));
      setStatus('error');
      return false;
    }
    savedRef.current = current;
    setError(null);
    setStatus(form.state.values.graph === current ? 'saved' : 'pending');
    return true;
  }, [form, update, workflowId, setStatus]);

  /** Saves now, including edits made while saving. Resolves false if a request failed. */
  const flush = useCallback(async () => {
    clearTimeout(timerRef.current);
    let ok: boolean;
    do {
      const next = queueRef.current.then(saveLatest);
      queueRef.current = next;
      ok = await next;
    } while (ok && !stoppedRef.current && form.state.values.graph !== savedRef.current);
    return ok;
  }, [form, saveLatest]);

  useEffect(() => {
    if (stoppedRef.current || graph === savedRef.current) return;
    if (statusRef.current !== 'saving') setStatus('pending');
    clearTimeout(timerRef.current);
    timerRef.current = setTimeout(() => void flush(), DELAY_MS);
  }, [graph, flush, setStatus]);

  useEffect(() => () => clearTimeout(timerRef.current), []);

  /**
   * Drops the unsent change and waits for the request in flight, e.g. before discarding the draft.
   * Follow with `reset()`.
   */
  const settle = useCallback(async () => {
    clearTimeout(timerRef.current);
    await queueRef.current;
  }, []);

  /** The server now has `graph` (e.g. the live graph after a discard); the form shows it too. */
  const reset = useCallback(
    (next: WorkflowGraph) => {
      savedRef.current = next;
      form.reset({ graph: next });
      setError(null);
      setStatus('saved');
    },
    [form, setStatus],
  );

  /** No more saves, e.g. once the workflow is deleted. */
  const stop = useCallback(() => {
    stoppedRef.current = true;
    clearTimeout(timerRef.current);
    setStatus('saved');
  }, [setStatus]);

  return { status, statusRef, error, flush, settle, reset, stop };
}
