import type { WorkflowNodeKind, WorkflowPlugin } from '@yay-automation/types';

import { cn } from '@/lib/utils';
import { plugins } from './PluginIcon';

/** Each kind's text colour, for the eyebrow above a step's name. */
const kindText: Record<WorkflowNodeKind, string> = {
  trigger: 'text-kind-trigger',
  condition: 'text-kind-condition',
  action: 'text-kind-action',
};

/**
 * The line above a step's name: its kind in the kind's colour, then its plugin ("ACTION ·
 * WooCommerce"). A step named after its kind ("Condition") shows only its plugin.
 */
export function StepEyebrow({
  kind,
  kindLabel,
  stepLabel,
  plugin,
}: {
  kind: WorkflowNodeKind;
  kindLabel: string;
  stepLabel?: string;
  plugin?: WorkflowPlugin;
}) {
  const showKind = stepLabel?.toLowerCase() !== kindLabel.toLowerCase();
  return (
    <div className={cn('text-[11px]/4 font-semibold tracking-wide uppercase', kindText[kind])}>
      {showKind && kindLabel}
      {plugin && (
        <span className="text-muted-foreground font-medium tracking-normal normal-case">
          {showKind && ' · '}
          {plugins[plugin].label}
        </span>
      )}
    </div>
  );
}
