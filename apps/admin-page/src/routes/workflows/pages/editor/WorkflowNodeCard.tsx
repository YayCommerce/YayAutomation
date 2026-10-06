import type { MouseEvent } from 'react';
import { useStore } from '@tanstack/react-form';
import type { NodeProps } from '@xyflow/react';
import type { WorkflowBranch, WorkflowNodeKind } from '@yay-automation/types';
import { GitBranch, Play, Plus, Replace, Trash2 } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { addNode, createId, hasChild } from '../../model/graph';
import type { FlowNode } from '../../shared/canvas/flow';
import { branchLeft, kindMeta, NodeCardView } from '../../shared/canvas/NodeCardView';
import { useEditorContext } from './editor.context';
import { useWorkflowFormContext } from './useWorkflowForm';

/** Round outline control, like the "+" buttons. `nodrag` stops ReactFlow from treating a click as a drag start. */
const roundButton = 'nodrag size-8 rounded-full';

/**
 * Hidden until the card is hovered (or the control is focused / its menu open), then fades and grows
 * in, like Shopify Flow. `slideFrom` adds a short slide toward its resting place.
 */
const onHover = (slideFrom: 'left' | 'top') =>
  [
    // `!`: the kit button's own `transition-default` would otherwise decide what animates.
    'opacity-0 scale-75 transition-[opacity,scale,translate,color,background-color,border-color]! duration-200! ease-out! motion-reduce:transition-none!',
    slideFrom === 'left' ? '-translate-x-1.5' : '-translate-y-1.5',
    'group-hover:opacity-100 group-hover:scale-100 group-hover:translate-0',
    'focus-visible:opacity-100 focus-visible:scale-100 focus-visible:translate-0',
    'data-[state=open]:opacity-100 data-[state=open]:scale-100 data-[state=open]:translate-0',
  ].join(' ');

/**
 * Editable card: adds a hover control on its right (swap the trigger, delete any other step) and "+"
 * controls below. `nodeTypes` maps each kind here. Clicking the card opens its panel (the canvas's
 * `onNodeClick`), so the controls stop propagation.
 */
export function WorkflowNodeCard({ id, type, data, selected }: NodeProps<FlowNode>) {
  const { openNodeId, openPanel, issueModes, requestDelete } = useEditorContext();
  // Steps hang off a chosen trigger (its data decides what they can use) or a chosen action.
  const canAdd = type === 'condition' || !!data.block;

  const control =
    type === 'trigger' ? (
      data.block && (
        <Button
          variant="outline"
          size="icon-sm"
          className={`${roundButton} ${onHover('left')}`}
          aria-label={__('Change trigger', 'yay-automation')}
          onClick={(event: MouseEvent) => {
            event.stopPropagation();
            openPanel(id, true);
          }}
        >
          <Replace />
        </Button>
      )
    ) : (
      <Button
        variant="outline"
        size="icon-sm"
        className={`${roundButton} ${onHover('left')}`}
        aria-label={__('Delete step', 'yay-automation')}
        onClick={(event: MouseEvent) => {
          event.stopPropagation();
          requestDelete([id]);
        }}
      >
        <Trash2 />
      </Button>
    );

  return (
    <NodeCardView
      type={type}
      data={data}
      selected={selected || openNodeId === id}
      issue={issueModes.get(id)}
      className="cursor-pointer"
      actions={control}
    >
      {!canAdd ? null : type === 'condition' ? (
        <>
          <AddNodeButton nodeId={id} branch="then" left={branchLeft.then} />
          <AddNodeButton nodeId={id} branch="otherwise" left={branchLeft.otherwise} />
        </>
      ) : (
        <AddNodeButton nodeId={id} left="50%" />
      )}
    </NodeCardView>
  );
}

/** "+" under a source handle. Hidden once that handle already has a child. */
function AddNodeButton({ nodeId, branch, left }: { nodeId: string; branch?: WorkflowBranch; left: string }) {
  const form = useWorkflowFormContext();
  const { openPanel } = useEditorContext();
  // Subscribe to just this boolean so the button re-renders only when it flips.
  const occupied = useStore(form.store, (state) => hasChild(state.values.graph, nodeId, branch));

  if (occupied) return null;

  // The new step's panel opens right away: an action on its picker, a condition on its criteria.
  const add = (kind: 'condition' | 'action') => {
    const id = createId(kind);
    form.setFieldValue('graph', (graph) => addNode(graph, nodeId, kind, branch, id));
    openPanel(id);
  };

  return (
    <DropdownMenu>
      {/* The padding bridges the gap under the card, so the pointer can reach "+" without losing the hover. */}
      <div
        className="nodrag absolute top-full -translate-x-1/2 pt-3"
        style={{ left }}
        onClick={(event) => event.stopPropagation()}
      >
        <DropdownMenuTrigger asChild>
          <Button
            variant="outline"
            size="icon-sm"
            className={`${roundButton} ${onHover('top')}`}
            aria-label={__('Add step', 'yay-automation')}
          >
            <Plus />
          </Button>
        </DropdownMenuTrigger>
      </div>
      {/* Portaled, but React events still bubble to the node; keep menu clicks from opening its settings. */}
      <DropdownMenuContent onClick={(event) => event.stopPropagation()}>
        <DropdownMenuItem onSelect={() => add('condition')}>
          <GitBranch />
          {kindMeta.condition.label}
        </DropdownMenuItem>
        <DropdownMenuItem onSelect={() => add('action')}>
          <Play />
          {kindMeta.action.label}
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

export const nodeTypes = {
  trigger: WorkflowNodeCard,
  condition: WorkflowNodeCard,
  action: WorkflowNodeCard,
} satisfies Record<WorkflowNodeKind, unknown>;
