import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';

const markerVariants = cva('inline-flex items-center gap-1.5 text-xs', {
  variants: {
    variant: {
      default: 'text-muted-foreground',
      border: 'text-muted-foreground w-full border-b pb-2',
      separator:
        'text-muted-foreground w-full before:bg-border after:bg-border before:h-px before:flex-1 after:h-px after:flex-1',
    },
  },
  defaultVariants: {
    variant: 'default',
  },
});

function Marker({
  className,
  variant,
  asChild: _asChild,
  ...props
}: React.ComponentProps<'div'> & VariantProps<typeof markerVariants> & { asChild?: boolean }) {
  return <div data-slot="marker" className={cn(markerVariants({ variant }), className)} {...props} />;
}

function MarkerIcon({ className, ...props }: React.ComponentProps<'span'>) {
  return (
    <span
      data-slot="marker-icon"
      aria-hidden="true"
      className={cn('[&_svg]:size-3 [&_svg]:shrink-0', className)}
      {...props}
    />
  );
}

function MarkerContent({ className, ...props }: React.ComponentProps<'span'>) {
  return <span data-slot="marker-content" className={cn('', className)} {...props} />;
}

export { Marker, MarkerIcon, MarkerContent, markerVariants };
