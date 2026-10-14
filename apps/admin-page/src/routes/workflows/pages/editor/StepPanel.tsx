import type { ReactNode } from 'react';
import { useStore } from '@tanstack/react-form';
import type { StepId, WorkflowGraph } from '@yay-automation/types';
import { X } from 'lucide-react';
import { m } from 'motion/react';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { setNodeStep, updateNodeConfig } from '../../model/graph';
import { availableData, sameItems } from '../../model/validation';
import { kindMeta } from '../../shared/canvas/NodeCardView';
import { StepEyebrow, StepTile } from '../../shared/ui/StepTile';
import { stepOf } from '../../steps';
import { alwaysAvailable } from '../../steps/core/data';
import { FeedbackContext, type Feedback } from '../../steps/core/fields/feedback';
import { VariableScopeContext } from '../../steps/core/fields/variableScope';
import { availableVariables, type Variable } from '../../steps/core/variables';
import { StepPicker } from './StepPicker';
import { useWorkflowFormContext } from './useWorkflowForm';

/** Same keys in the same order: labels and types only change with them. */
const sameVariables = (a: Variable[], b: Variable[]) =>
  sameItems(
    a.map((variable) => variable.key),
    b.map((variable) => variable.key),
  );

/**
 * Panel width for a canvas this wide: roomy on desktops (480px), narrower on
 * laptops and tablets so the graph keeps some room beside it.
 */
export const panelWidthFor = (canvasWidth: number) => (canvasWidth >= 1200 ? 480 : canvasWidth >= 900 ? 420 : 360);

/**
 * Content of the open step's panel (inside `StepPanelFrame`): its step picker while a trigger or
 * action has none (or `picking`, to swap), then the step's header and settings. Edits go straight into the form; autosave persists them.
 */
export function StepPanel({
  nodeId,
  picking,
  feedback,
  closable,
  onPicked,
  onClose,
}: {
  nodeId: string;
  picking: boolean;
  /** How the form shows its errors (see `Feedback`). */
  feedback: Feedback;
  /** False for a new workflow's first trigger picker: a trigger must be chosen first. */
  closable: boolean;
  onPicked: () => void;
  onClose: () => void;
}) {
  const form = useWorkflowFormContext();
  // Null when the step was deleted while its panel was open.
  const node = useStore(form.store, (state) => state.values.graph.nodes.find((n) => n.id === nodeId) ?? null);
  // The trigger decides which variables and actions the step can use.
  const triggerStep = useStore(form.store, (state) => {
    const trigger = state.values.graph.nodes.find((n) => n.kind === 'trigger');
    return trigger ? stepOf(trigger.data) : undefined;
  });
  const available = useStore(
    form.store,
    (state) => availableData(state.values.graph)?.(nodeId) ?? alwaysAvailable,
    sameItems,
  );
  const variables = useStore(form.store, (state) => availableVariables(state.values.graph, nodeId), sameVariables);
  const triggerLabel = triggerStep?.label ?? null;
  if (!node) return null;

  const stepDef = stepOf(node.data);
  const update = (change: (graph: WorkflowGraph) => WorkflowGraph) => form.setFieldValue('graph', change);

  const close = closable && (
    <Button variant="ghost" size="icon-sm" aria-label={__('Close', 'yay-automation')} onClick={onClose}>
      <X />
    </Button>
  );

  if (node.kind !== 'condition' && (!stepDef || picking)) {
    const pick = (id: StepId) => {
      update((graph) => setNodeStep(graph, node.id, id));
      onPicked();
    };
    return (
      <>
        <div className="flex items-center justify-between gap-2 px-4 pt-3">
          <h2 className="text-[15px] font-semibold">{kindMeta[node.kind].choose}</h2>
          {close}
        </div>
        <div className="min-h-0 flex-1 overflow-y-auto p-4 pt-3">
          <StepPicker kind={node.kind} available={node.kind === 'action' ? available : undefined} onPick={pick} />
        </div>
      </>
    );
  }
  if (!stepDef) return null;

  return (
    <>
      {/* The card's tile and eyebrow, so the panel reads as the step it belongs to. */}
      <div className="flex items-start gap-3 border-b p-4">
        <StepTile kind={node.kind} icon={stepDef.icon} size="lg" />
        <div className="min-w-0 flex-1">
          <StepEyebrow
            kind={node.kind}
            kindLabel={kindMeta[node.kind].label}
            stepLabel={stepDef.label}
            plugin={stepDef.plugin}
          />
          <h2 className="text-[15px]/5 font-semibold">{stepDef.label}</h2>
          <p className="text-muted-foreground mt-0.5 text-xs">{stepDef.description}</p>
        </div>
        {close}
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto p-4">
        {stepDef.Form ? (
          <FeedbackContext.Provider value={feedback}>
            <VariableScopeContext.Provider value={{ variables, trigger: triggerLabel }}>
              {/* Keyed by step and step, so switching either starts a fresh form. */}
              <stepDef.Form
                key={`${node.id}:${stepDef.id}`}
                config={node.data.config}
                onChange={(config) => update((graph) => updateNodeConfig(graph, node.id, config))}
              />
            </VariableScopeContext.Provider>
          </FeedbackContext.Provider>
        ) : (
          <p className="text-muted-foreground text-sm">{__('No settings needed.', 'yay-automation')}</p>
        )}
      </div>
    </>
  );
}

/**
 * The panel's card. Slides in from the left when it opens and back out when it closes; switching
 * steps only swaps the content. Render inside `AnimatePresence`.
 */
export function StepPanelFrame({ width, children }: { width: number; children: ReactNode }) {
  return (
    // ReactFlow's Backspace-to-delete listens on the whole document; `nokey` makes it ignore keys
    // pressed in here (buttons, selects), so editing never deletes the selected step.
    <m.aside
      data-step-panel
      initial={{ x: '-110%' }}
      animate={{ x: 0 }}
      exit={{ x: '-110%' }}
      transition={{ type: 'tween', ease: 'easeOut', duration: 0.25 }}
      // While "Add a variable" is open (`data-dimmed`, set by the picker): lifted above the dialog
      // overlay and dimmed by its own layer, so only the element being edited stays bright.
      className="nokey bg-card text-card-foreground group/panel absolute inset-y-3 left-3 z-20 flex max-w-[calc(100%-1.5rem)] flex-col rounded-xl border shadow-lg data-[dimmed=on]:z-100002"
      style={{ width }}
    >
      {children}
      <div
        aria-hidden
        // `-inset-px`: over the panel's border too, so no light edge shows around the dimmed panel.
        className="pointer-events-none absolute -inset-px z-10 hidden rounded-xl bg-black/50 group-data-[dimmed=on]/panel:block"
      />
    </m.aside>
  );
}
