import type { WorkflowNodeKind, WorkflowPlugin } from '@yay-automation/types';
import { cva, type VariantProps } from 'class-variance-authority';
import { GitBranch, Play, Zap, type LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';
import { plugins } from './PluginIcon';

/** Each kind's icon, for a step not chosen yet or one without its own icon. */
export const kindIcons: Record<WorkflowNodeKind, LucideIcon> = { trigger: Zap, condition: GitBranch, action: Play };

/** Each kind's text colour, for the eyebrow above a step's name. */
const kindText: Record<WorkflowNodeKind, string> = {
  trigger: 'text-kind-trigger',
  condition: 'text-kind-condition',
  action: 'text-kind-action',
};

const tileVariants = cva('flex shrink-0 items-center justify-center', {
  variants: {
    kind: {
      trigger: 'bg-kind-trigger/15 text-kind-trigger',
      condition: 'bg-kind-condition/15 text-kind-condition',
      action: 'bg-kind-action/15 text-kind-action',
      // Not chosen yet.
      empty: 'bg-muted text-muted-foreground',
    },
    size: {
      sm: 'size-6.5 rounded-md [&>svg]:size-3.5',
      md: 'size-7.5 rounded-lg [&>svg]:size-4',
      lg: 'size-9 rounded-[9px] [&>svg]:size-4.5',
    },
  },
  defaultVariants: { size: 'md' },
});

/**
 * A step's icon on a tile tinted by its kind: the same tile on the canvas, in the picker, the
 * panel header, template cards and the overview. Decorative; the step's name sits next to it.
 */
export function StepTile({
  kind,
  icon,
  empty,
  size,
  className,
}: {
  kind: WorkflowNodeKind;
  /** The step's icon; the kind's icon when absent. */
  icon?: LucideIcon;
  /** No step chosen yet: a muted tile. */
  empty?: boolean;
  className?: string;
} & Pick<VariantProps<typeof tileVariants>, 'size'>) {
  const Icon = icon ?? kindIcons[kind];
  return (
    <span className={cn(tileVariants({ kind: empty ? 'empty' : kind, size }), className)} aria-hidden>
      <Icon />
    </span>
  );
}

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
