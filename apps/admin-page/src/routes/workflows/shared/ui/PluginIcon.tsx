import type { WorkflowPlugin } from '@yay-automation/types';

import type { PluginMeta } from '@/types/localize';
import { cn } from '@/lib/utils';
import { automationMeta } from '../../steps/core/pending';

/** Plugin facts from PHP (`Workflow\Extend\Plugins`), by id. */
export const plugins = Object.fromEntries(automationMeta.plugins.map((plugin) => [plugin.id, plugin])) as Record<
  WorkflowPlugin,
  PluginMeta
>;

const tones: Record<WorkflowPlugin, string> = {
  woocommerce: 'bg-primary/10 text-primary',
  // Same look as the app's own icon in the header.
  yayautomation: 'bg-primary text-primary-foreground',
};

/** Small tinted tile with the plugin's logo. */
export function PluginIcon({ plugin, className }: { plugin: WorkflowPlugin; className?: string }) {
  const { label, logo } = plugins[plugin];

  return (
    <span
      title={label}
      className={cn('flex size-7 shrink-0 items-center justify-center rounded-md', tones[plugin], className)}
    >
      {/* PHP cleans the SVG with wp_kses() and it draws in currentColor. */}
      <span className="flex [&>svg]:size-4" dangerouslySetInnerHTML={{ __html: logo }} />
      <span className="sr-only">{label}</span>
    </span>
  );
}
