import type { ComponentProps } from 'react';

import { cn } from '@/lib/utils';
import { Alert } from '@/components/ui/alert';

/** The kit's destructive Alert on a red tint (Images 13, 24). Shared by the editor banner and the Overview. */
export function ErrorAlert({ className, ...props }: ComponentProps<typeof Alert>) {
  return <Alert destructive className={cn('border-destructive/30 bg-destructive/10 border', className)} {...props} />;
}
