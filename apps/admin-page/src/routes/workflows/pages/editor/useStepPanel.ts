import { useCallback, useState } from 'react';
import { useStore } from '@tanstack/react-form';
import type { WorkflowGraph } from '@yay-automation/types';

import { hasChild, removeNode } from '../../model/graph';
import type { WorkflowForm } from './useWorkflowForm';

export type PanelState = { nodeId: string; picking: boolean };

/**
 * Which step's panel is open. A new workflow opens on its trigger picker, which can't be closed
 * until a trigger is chosen (`locked`). Leaving a step: an action still without a step (and
 * nothing below it) is dropped; any other step is handed to `onLeave`.
 * `formVersion` goes in the open panel's key, so `reset()` can make it start over.
 */
export function useStepPanel(form: WorkflowForm, onLeave: (nodeId: string) => void) {
  const [panel, setPanel] = useState<PanelState | null>(() => {
    const trigger = form.state.values.graph.nodes.find((node) => node.kind === 'trigger');
    return trigger && !trigger.data.block ? { nodeId: trigger.id, picking: false } : null;
  });

  const unchosenTriggerId = useStore(form.store, (state) => {
    const trigger = state.values.graph.nodes.find((node) => node.kind === 'trigger');
    return trigger && !trigger.data.block ? trigger.id : null;
  });
  const locked = !!panel && panel.nodeId === unchosenTriggerId;

  const showPanel = useCallback(
    (next: PanelState | null) => {
      const left = panel && panel.nodeId !== next?.nodeId ? panel.nodeId : null;
      if (left) {
        const graph = form.state.values.graph;
        const node = graph.nodes.find((n) => n.id === left);
        if (node?.kind === 'action' && !node.data.block && !hasChild(graph, left)) {
          form.setFieldValue('graph', (g) => removeNode(g, left));
        } else {
          onLeave(left);
        }
      }
      setPanel(next);
    },
    [form, panel, onLeave],
  );

  const [formVersion, setFormVersion] = useState(0);
  /**
   * The form now holds `graph` (e.g. after a discard): the open panel starts over from it on the
   * step's settings, since forms keep their own values. A step only in the discarded edits has no
   * panel to show anymore.
   */
  const reset = useCallback((graph: WorkflowGraph) => {
    setFormVersion((version) => version + 1);
    setPanel((open) =>
      open && graph.nodes.some((node) => node.id === open.nodeId) ? { nodeId: open.nodeId, picking: false } : null,
    );
  }, []);

  return { panel, setPanel, showPanel, locked, formVersion, reset };
}
