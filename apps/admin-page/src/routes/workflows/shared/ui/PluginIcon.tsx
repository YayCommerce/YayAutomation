import type { WorkflowPlugin } from '@yaymatik/types';

import type { PluginMeta } from '@/types/localize';
import { cn } from '@/lib/utils';

/** Plugin facts from PHP (`Workflow\ModuleRegistry`), by id. */
export const plugins = Object.fromEntries(
  window.yayMatikMeta.automation.plugins.map((plugin) => [plugin.id, plugin]),
) as Record<WorkflowPlugin, PluginMeta>;

/** Core's own tints; another plugin's logo sits on a neutral tile. */
const tones: Partial<Record<WorkflowPlugin, string>> = {
  woocommerce: 'bg-primary/10 text-primary',
  // Same look as the app's own icon in the header.
  yaymatik: 'bg-primary text-primary-foreground',
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
  const meta = plugins[plugin] as PluginMeta | undefined;
  if (!meta) return null;
  const { label, logo } = meta;
  // The logo file only gives the shape: painted with the tile's text colour, so it follows the theme.
  const mask = `url("${logo}") center / contain no-repeat`;

  return (
    <span
      title={noTitle ? undefined : label}
      className={cn(
        'flex shrink-0 items-center justify-center',
        size === 'sm' ? 'size-5.5 rounded-md' : 'size-7 rounded-md',
        tones[plugin] ?? 'bg-muted text-foreground',
        className,
      )}
    >
      <span
        aria-hidden
        className={cn('bg-current', size === 'sm' ? 'size-3' : 'size-4')}
        style={{ mask, WebkitMask: mask }}
      />
      <span className="sr-only">{label}</span>
    </span>
  );
}
