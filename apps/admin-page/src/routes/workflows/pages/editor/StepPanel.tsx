import { useState, type ReactNode } from 'react';
import { useStore } from '@tanstack/react-form';
import type { BlockId, WorkflowGraph, WorkflowNode } from '@yay-automation/types';
import { MessageSquareText, X } from 'lucide-react';
import { m } from 'motion/react';
import { __, sprintf } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { blockOf } from '../../blocks';
import { setNodeBlock, updateNodeConfig, updateNodeDescription } from '../../model/graph';
import { availableData } from '../../model/validation';
import { kindMeta } from '../../shared/canvas/NodeCardView';
import { plugins } from '../../shared/ui/PluginIcon';
import { BlockPicker } from './BlockPicker';
import { useWorkflowFormContext } from './useWorkflowForm';

/** Floats over the left of the canvas; the canvas stays usable beside it. */
export const PANEL_WIDTH = 420;

const pickerTitles = {
  trigger: __('Select trigger', 'yay-automation'),
  action: __('Select action', 'yay-automation'),
};

/** "WooCommerce trigger", "WooCommerce action". */
const kindOfPlugin = (node: WorkflowNode, plugin: string) =>
  node.kind === 'trigger'
    ? /* translators: %s: plugin name */ sprintf(__('%s trigger', 'yay-automation'), plugin)
    : /* translators: %s: plugin name */ sprintf(__('%s action', 'yay-automation'), plugin);

/**
 * Content of the open step's panel (inside `StepPanelFrame`): its block picker while a trigger or
 * action has none (or `picking`, to swap), then the block's header, a description note and its
 * settings. Edits go straight into the form; autosave persists them.
 */
export function StepPanel({
  nodeId,
  picking,
  onPicked,
  onClose,
}: {
  nodeId: string;
  picking: boolean;
  onPicked: () => void;
  onClose: () => void;
}) {
  const form = useWorkflowFormContext();
  // Null when the step was deleted while its panel was open.
  const node = useStore(form.store, (state) => state.values.graph.nodes.find((n) => n.id === nodeId) ?? null);
  // The trigger's data decides which variables and actions the step can use.
  const provides = useStore(form.store, (state) => {
    const trigger = state.values.graph.nodes.find((n) => n.kind === 'trigger');
    return (trigger && blockOf(trigger.data)?.provides) ?? [];
  });
  if (!node) return null;

  const block = blockOf(node.data);
  const available = availableData(provides);
  const update = (change: (graph: WorkflowGraph) => WorkflowGraph) => form.setFieldValue('graph', change);

  const close = (
    <Button variant="ghost" size="icon-sm" aria-label={__('Close', 'yay-automation')} onClick={onClose}>
      <X />
    </Button>
  );

  if (node.kind !== 'condition' && (!block || picking)) {
    const pick = (id: BlockId) => {
      update((graph) => setNodeBlock(graph, node.id, id));
      onPicked();
    };
    return (
      <>
        <div className="flex items-center justify-between gap-2 px-4 pt-3">
          <h2 className="text-sm font-semibold">{pickerTitles[node.kind]}</h2>
          {close}
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto p-4 pt-3">
          <BlockPicker kind={node.kind} available={node.kind === 'action' ? available : undefined} onPick={pick} />
        </div>
      </>
    );
  }
  if (!block) return null;

  const { icon: Icon } = kindMeta[node.kind];
  return (
    <>
      <div className="flex items-start gap-3 border-b p-4">
        <span className="flex size-9 shrink-0 items-center justify-center rounded-md border">
          <Icon className="size-4" aria-hidden />
        </span>
        <div className="min-w-0 flex-1">
          <h2 className="text-sm font-semibold">
            {block.label}
            {block.plugin && (
              <span className="text-muted-foreground font-normal">
                {' • '}
                {kindOfPlugin(node, plugins[block.plugin].label)}
              </span>
            )}
          </h2>
          <p className="text-muted-foreground mt-0.5 text-xs">{block.description}</p>
        </div>
        {close}
      </div>

      <div className="border-b px-4 py-2">
        <StepDescription
          key={node.id}
          value={node.data.description}
          onChange={(description) => update((graph) => updateNodeDescription(graph, node.id, description))}
        />
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto p-4">
        {block.Form ? (
          // Keyed by step and block, so switching either starts a fresh form.
          <block.Form
            key={`${node.id}:${block.id}`}
            config={node.data.config}
            onChange={(config) => update((graph) => updateNodeConfig(graph, node.id, config))}
            available={available}
          />
        ) : (
          <p className="text-muted-foreground text-sm">
            {__("This trigger doesn't require any configuration.", 'yay-automation')}
          </p>
        )}
      </div>
    </>
  );
}

/**
 * The panel's card. Slides in from the left when it opens and back out when it closes, like Shopify
 * Flow; switching steps only swaps the content. Render inside `AnimatePresence`.
 */
export function StepPanelFrame({ children }: { children: ReactNode }) {
  return (
    // ReactFlow's Backspace-to-delete listens on the whole document; `nokey` makes it ignore keys
    // pressed in here (buttons, selects), so editing never deletes the selected step.
    <m.aside
      initial={{ x: '-110%' }}
      animate={{ x: 0 }}
      exit={{ x: '-110%' }}
      transition={{ type: 'tween', ease: 'easeOut', duration: 0.25 }}
      className="nokey bg-card text-card-foreground absolute inset-y-3 left-3 z-20 flex max-w-[calc(100%-1.5rem)] flex-col rounded-xl border shadow-lg"
      style={{ width: PANEL_WIDTH }}
    >
      {children}
    </m.aside>
  );
}

/** "Add description" until there's a note; then the note, editable in place. */
function StepDescription({ value, onChange }: { value: string; onChange: (value: string) => void }) {
  const [editing, setEditing] = useState(false);

  if (!editing && !value) {
    return (
      <Button variant="ghost" size="sm" className="-ml-2" onClick={() => setEditing(true)}>
        <MessageSquareText />
        {__('Add description', 'yay-automation')}
      </Button>
    );
  }
  return (
    <Textarea
      autoFocus={editing}
      autoSize
      rows={2}
      value={value}
      placeholder={__('What this step is for', 'yay-automation')}
      aria-label={__('Description', 'yay-automation')}
      onChange={(event) => onChange(event.target.value)}
      onBlur={() => setEditing(false)}
    />
  );
}
