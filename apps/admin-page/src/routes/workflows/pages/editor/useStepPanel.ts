import { useCallback, useState } from 'react';
import { useStore } from '@tanstack/react-form';

import { hasChild, removeNode } from '../../model/graph';
import type { WorkflowForm } from './useWorkflowForm';

export type PanelState = { nodeId: string; picking: boolean };

/**
 * Which step's panel is open. A new workflow opens on its trigger picker, which can't be closed
 * until a trigger is chosen (`locked`). Leaving a step: an action still without a step (and
 * nothing below it) is dropped; any other step is handed to `onLeave`.
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

  return { panel, setPanel, showPanel, locked };
}
