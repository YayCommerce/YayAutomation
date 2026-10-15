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

/** Small tinted tile with the plugin's logo. `sm`: in lists and stacks (sidebar, card footers). */
export function PluginIcon({
  plugin,
  size = 'md',
  noTitle = false,
  className,
}: {
  plugin: WorkflowPlugin;
  size?: 'sm' | 'md';
  /** No native `title`: a tooltip shows the name instead. */
  noTitle?: boolean;
  className?: string;
}) {
  const { label, logo } = plugins[plugin];

  return (
    <span
      title={noTitle ? undefined : label}
      className={cn(
        'flex shrink-0 items-center justify-center',
        size === 'sm' ? 'size-5.5 rounded-md' : 'size-7 rounded-md',
        tones[plugin],
        className,
      )}
    >
      {/* PHP cleans the SVG with wp_kses() and it draws in currentColor. */}
      <span
        className={cn('flex', size === 'sm' ? '[&>svg]:size-3' : '[&>svg]:size-4')}
        dangerouslySetInnerHTML={{ __html: logo }}
      />
      <span className="sr-only">{label}</span>
    </span>
  );
}
