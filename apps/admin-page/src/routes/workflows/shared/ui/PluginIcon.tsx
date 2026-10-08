import type { WorkflowPlugin } from '@yay-automation/types';
import { ShoppingCart, Workflow, type LucideIcon } from 'lucide-react';

import type { PluginMeta } from '@/types/localize';
import { cn } from '@/lib/utils';
import { automationMeta } from '../../steps/core/pending';

/** Plugin facts from PHP (`Workflow\Extend\Plugins`), by id. */
export const plugins = Object.fromEntries(automationMeta.plugins.map((plugin) => [plugin.id, plugin])) as Record<
  WorkflowPlugin,
  PluginMeta
>;

const looks: Record<WorkflowPlugin, { icon: LucideIcon; className: string }> = {
  woocommerce: { icon: ShoppingCart, className: 'bg-primary/10 text-primary' },
  // Same look as the app's own icon in the header.
  yayautomation: { icon: Workflow, className: 'bg-primary text-primary-foreground' },
};

/** Small tinted tile for one plugin. */
export function PluginIcon({ plugin, className }: { plugin: WorkflowPlugin; className?: string }) {
  const { icon: Icon, className: tone } = looks[plugin];
  const { label } = plugins[plugin];

  return (
    <span title={label} className={cn('flex size-7 shrink-0 items-center justify-center rounded-md', tone, className)}>
      <Icon className="size-4" />
      <span className="sr-only">{label}</span>
    </span>
  );
}
