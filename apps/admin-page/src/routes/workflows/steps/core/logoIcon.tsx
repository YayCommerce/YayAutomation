import type { LucideIcon, LucideProps } from 'lucide-react';

import { cn } from '@/lib/utils';

const icons = new Map<string, LucideIcon>();

/**
 * Another plugin's step that names no icon shows the plugin's logo, painted in the tile's text
 * colour. An `<svg>` so tiles size it like a lucide icon (`[&>svg]:size-4`).
 */
export function logoIcon(plugin: string): LucideIcon | undefined {
  const logo = window.yayMatikMeta.automation.plugins.find((meta) => meta.id === plugin)?.logo;
  if (!logo) return undefined;
  let icon = icons.get(plugin);
  if (!icon) {
    const mask = `url("${logo}") center / contain no-repeat`;
    icon = (({ className }: LucideProps) => (
      <svg aria-hidden className={cn('bg-current', className)} style={{ mask, WebkitMask: mask }} />
    )) as unknown as LucideIcon;
    icons.set(plugin, icon);
  }
  return icon;
}
