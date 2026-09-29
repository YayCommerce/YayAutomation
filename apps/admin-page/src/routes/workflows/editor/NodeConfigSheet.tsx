import { useStore } from '@tanstack/react-form';

import { Sheet, SheetContent, SheetDescription, SheetHeader, SheetTitle } from '@/components/ui/sheet';
import { blocks } from '../blocks';
import { useWorkflowFormContext } from './useWorkflowForm';
import { updateNodeConfig } from './workflow.graph';

/** Settings for the clicked step. Edits go straight into the workflow draft; Save persists them. */
export function NodeConfigSheet({ nodeId, onClose }: { nodeId: string | null; onClose: () => void }) {
  const form = useWorkflowFormContext();
  // Null when nothing is open, or the node was deleted while its sheet was open.
  const node = useStore(form.store, (state) => state.values.graph.nodes.find((n) => n.id === nodeId) ?? null);
  const block = node && blocks[node.data.block];

  return (
    <Sheet open={!!node} onOpenChange={(open) => !open && onClose()}>
      {/* The edited node stays selected, and ReactFlow's Backspace-to-delete listens on the whole
          document; `nokey` makes it ignore keys pressed inside the sheet (buttons, selects). */}
      <SheetContent className="nokey">
        {node && block && (
          <>
            <SheetHeader>
              <SheetTitle>{block.label}</SheetTitle>
              <SheetDescription>{block.description}</SheetDescription>
            </SheetHeader>
            <div className="min-h-0 flex-1 overflow-y-auto p-5">
              {/* Keyed by node so switching steps starts a fresh form. */}
              <block.Form
                key={node.id}
                config={node.data.config}
                onChange={(config) => form.setFieldValue('graph', (graph) => updateNodeConfig(graph, node.id, config))}
              />
            </div>
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}
