import { useCallback, useEffect, useRef, useState } from 'react';
import type { WorkflowGraph } from '@yay-automation/types';

import { useAutosaveMutation } from '../../api/workflow.query';
import type { WorkflowForm } from './useWorkflowForm';

const DELAY_MS = 800;

/**
 * `saved`: the server has the form's graph. `pending`: a change is waiting for the debounce or
 * behind a request. `saving`: a request is out. `error`: the last request failed; nothing is retried
 * until `flush()` runs (Retry, an action, or the next edit).
 */
export type AutosaveStatus = 'saved' | 'pending' | 'saving' | 'error';

/**
 * Saves the form's graph shortly after each change, one request at a time. The server decides where
 * the graph goes (the draft once the workflow has ever been turned on, the workflow itself before).
 */
export function useAutosave(form: WorkflowForm, workflowId: string) {
  // `mutateAsync` is stable across renders (the mutation object isn't), so the callbacks below are too.
  const { mutateAsync: update } = useAutosaveMutation();

  // What the server has. Compared by identity: every edit makes a new graph object.
  const savedRef = useRef<WorkflowGraph>(form.state.values.graph);
  const queueRef = useRef<Promise<boolean>>(Promise.resolve(true));
  const timerRef = useRef<ReturnType<typeof setTimeout>>(undefined);
  // `paused`: nothing is sent (e.g. while the draft is being discarded). `stopped`: never again.
  const pausedRef = useRef(false);
  const stoppedRef = useRef(false);
  const [status, setStatusState] = useState<AutosaveStatus>('saved');
  const [error, setError] = useState<Error | null>(null);
  // Mirrors `status` for callers that read it outside render (route blocker) and for the callbacks.
  const statusRef = useRef<AutosaveStatus>('saved');
  const setStatus = useCallback((next: AutosaveStatus) => {
    statusRef.current = next;
    setStatusState(next);
  }, []);

  const hasUnsent = useCallback(() => form.state.values.graph !== savedRef.current, [form]);

  const saveLatest = useCallback(async () => {
    const current = form.state.values.graph;
    if (pausedRef.current || stoppedRef.current || current === savedRef.current) return true;
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
    setStatus(hasUnsent() ? 'pending' : 'saved');
    return true;
  }, [form, update, workflowId, setStatus, hasUnsent]);

  const enqueue = useCallback(() => {
    const next = queueRef.current.then(saveLatest);
    queueRef.current = next;
    return next;
  }, [saveLatest]);

  /** Debounced save. Edits made during the request wait for the next debounce, not a back-to-back save. */
  const schedule = useCallback(() => {
    const arm = () => {
      clearTimeout(timerRef.current);
      timerRef.current = setTimeout(() => {
        void enqueue().then((ok) => {
          if (ok && !pausedRef.current && !stoppedRef.current && hasUnsent()) arm();
        });
      }, DELAY_MS);
    };
    arm();
  }, [enqueue, hasUnsent]);

  /** Saves now, including edits made while saving. Resolves false if a request failed. */
  const flush = useCallback(async () => {
    clearTimeout(timerRef.current);
    let ok: boolean;
    do {
      ok = await enqueue();
    } while (ok && !pausedRef.current && !stoppedRef.current && hasUnsent());
    return ok;
  }, [enqueue, hasUnsent]);

  // Watches the store directly: re-rendering the editor on every drag frame just to arm a timer
  // would be wasted work. `status` only changes when there's something new to show.
  useEffect(() => {
    let last = form.state.values.graph;
    const subscription = form.store.subscribe(() => {
      const graph = form.state.values.graph;
      if (graph === last) return;
      last = graph;
      if (pausedRef.current || stoppedRef.current || graph === savedRef.current) return;
      if (statusRef.current !== 'saving' && statusRef.current !== 'pending') setStatus('pending');
      schedule();
    });
    return () => {
      subscription.unsubscribe();
      clearTimeout(timerRef.current);
    };
  }, [form, schedule, setStatus]);

  /**
   * Stops sending and waits until no request is out, e.g. before discarding the draft. Follow with
   * `reset()` on success or `resume()` on failure.
   */
  const settle = useCallback(async () => {
    pausedRef.current = true;
    clearTimeout(timerRef.current);
    // A flush loop can queue another save once the current tail resolves; wait until it's quiet.
    let tail: Promise<boolean>;
    do {
      tail = queueRef.current;
      await tail;
    } while (tail !== queueRef.current);
  }, []);

  /** Undoes `settle()`: unsent edits save again. */
  const resume = useCallback(() => {
    pausedRef.current = false;
    if (hasUnsent()) {
      setStatus('pending');
      schedule();
    } else if (statusRef.current !== 'error') {
      setStatus('saved');
    }
  }, [hasUnsent, schedule, setStatus]);

  /** The server now has `graph` (e.g. the live graph after a discard); the form shows it too. */
  const reset = useCallback(
    (next: WorkflowGraph) => {
      savedRef.current = next;
      form.reset({ graph: next });
      pausedRef.current = false;
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

  return { status, statusRef, error, flush, settle, resume, reset, stop };
}
