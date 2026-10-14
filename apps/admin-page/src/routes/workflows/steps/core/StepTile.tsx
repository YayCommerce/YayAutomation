import type { WorkflowNodeKind } from '@yay-automation/types';
import { cva, type VariantProps } from 'class-variance-authority';
import { Database, GitBranch, Play, Zap, type LucideIcon } from 'lucide-react';

import { cn } from '@/lib/utils';

/** Each kind's icon, for a step not chosen yet or one without its own icon. */
export const kindIcons: Record<WorkflowNodeKind, LucideIcon> = { trigger: Zap, condition: GitBranch, action: Play };

const tileVariants = cva('flex shrink-0 items-center justify-center', {
  variants: {
    kind: {
      trigger: 'bg-kind-trigger/15 text-kind-trigger',
      condition: 'bg-kind-condition/15 text-kind-condition',
      action: 'bg-kind-action/15 text-kind-action',
      // Not chosen yet.
      empty: 'bg-muted text-muted-foreground',
      // Not a step: data every workflow has (the site), in "Insert variable".
      neutral: 'bg-muted-foreground/15 text-muted-foreground',
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
 * panel header, template cards, the overview and "Insert variable" (data tinted by the step it
 * comes from). Decorative; the step's name sits next to it.
 */
export function StepTile({
  kind,
  icon,
  empty,
  size,
  className,
}: {
  kind: WorkflowNodeKind | 'neutral';
  /** The step's icon; the kind's icon when absent. */
  icon?: LucideIcon;
  /** No step chosen yet: a muted tile. */
  empty?: boolean;
  className?: string;
} & Pick<VariantProps<typeof tileVariants>, 'size'>) {
  const Icon = icon ?? (kind === 'neutral' ? Database : kindIcons[kind]);
  return (
    <span className={cn(tileVariants({ kind: empty ? 'empty' : kind, size }), className)} aria-hidden>
      <Icon />
    </span>
  );
}
