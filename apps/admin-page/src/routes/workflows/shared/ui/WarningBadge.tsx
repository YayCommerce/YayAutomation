import { forwardRef, type ComponentPropsWithoutRef } from 'react';

import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { appBadge } from './WorkflowStatusBadge';

/** Pale amber "Draft changes" badge (list and editor header). Takes a ref, so it can be a tooltip's trigger. */
export const WarningBadge = forwardRef<HTMLSpanElement, Omit<ComponentPropsWithoutRef<typeof Badge>, 'variant'>>(
  ({ className, ...props }, ref) => (
    <Badge
      ref={ref}
      variant="secondary"
      className={cn(appBadge, 'bg-warning-soft text-warning-soft-foreground', className)}
      {...props}
    />
  ),
);
