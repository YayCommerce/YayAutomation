import { cn } from '@/lib/utils';

/** A small segmented tab bar: the kit's `Segmented`, made compact. */
export const tabBarClass = 'h-auto gap-0.5 rounded-lg p-[3px]';
export const tabClass = cn(
  'h-auto rounded-md border-0 px-2.5 py-0.75 text-[13px] font-normal data-[shape=square]:rounded-md',
  'text-muted-foreground-600 dark:text-muted-foreground-600 hover:text-foreground hover:bg-transparent',
  'data-[state=on]:bg-card dark:data-[state=on]:bg-card data-[state=on]:text-card-foreground dark:data-[state=on]:text-card-foreground',
  'data-[state=on]:font-medium data-[state=on]:shadow-[0_1px_2px_#0000001a]',
);
