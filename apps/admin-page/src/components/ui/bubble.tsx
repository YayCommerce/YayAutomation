import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';

const bubbleVariants = cva('relative', {
  variants: {
    variant: {
      default: '',
      secondary: '',
      muted: '',
      tinted: '',
      outline: '',
      ghost: 'w-full',
      destructive: '',
    },
    align: {
      start: 'self-start',
      end: 'self-end',
    },
  },
  defaultVariants: {
    variant: 'default',
    align: 'start',
  },
});

const bubbleContentVariants = cva('rounded-xl px-3 py-2 text-sm break-words', {
  variants: {
    variant: {
      default: 'bg-primary text-primary-foreground',
      secondary: 'bg-secondary text-secondary-foreground',
      muted: 'bg-muted text-muted-foreground',
      tinted: 'bg-primary/10 text-primary',
      outline: 'border bg-background text-foreground',
      ghost: 'bg-transparent text-foreground px-0',
      destructive: 'bg-destructive text-destructive-foreground',
    },
  },
  defaultVariants: {
    variant: 'default',
  },
});

type BubbleVariants = VariantProps<typeof bubbleVariants>;

const BubbleContext = React.createContext<{ variant: BubbleVariants['variant'] }>({ variant: 'default' });

function Bubble({ className, variant = 'default', align, ...props }: React.ComponentProps<'div'> & BubbleVariants) {
  return (
    <BubbleContext.Provider value={{ variant }}>
      <div data-slot="bubble" className={cn(bubbleVariants({ variant, align }), className)} {...props} />
    </BubbleContext.Provider>
  );
}

function BubbleContent({ className, ...props }: React.ComponentProps<'div'>) {
  const { variant } = React.useContext(BubbleContext);
  return <div data-slot="bubble-content" className={cn(bubbleContentVariants({ variant }), className)} {...props} />;
}

function BubbleReactions({
  className,
  side = 'bottom',
  align = 'end',
  ...props
}: React.ComponentProps<'div'> & { side?: 'top' | 'bottom'; align?: 'start' | 'end' }) {
  return (
    <div
      data-slot="bubble-reactions"
      data-side={side}
      data-align={align}
      className={cn(
        'absolute flex items-center gap-0.5',
        side === 'bottom' ? '-bottom-3' : '-top-3',
        align === 'end' ? 'right-2' : 'left-2',
        className,
      )}
      {...props}
    />
  );
}

function BubbleGroup({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="bubble-group" className={cn('flex flex-col gap-1', className)} {...props} />;
}

export { Bubble, BubbleContent, BubbleReactions, BubbleGroup, bubbleVariants };
