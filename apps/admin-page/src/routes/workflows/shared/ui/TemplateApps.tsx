import type { WorkflowTemplateApp } from '@yay-automation/types';
import { Mail, ShoppingCart, Workflow, type LucideIcon } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';

export const appMeta: Record<WorkflowTemplateApp, { label: string; icon: LucideIcon; className: string }> = {
  woocommerce: {
    label: __('WooCommerce', 'yay-automation'),
    icon: ShoppingCart,
    className: 'bg-primary/10 text-primary',
  },
  automation: { label: __('YayAutomation', 'yay-automation'), icon: Workflow, className: 'bg-success/10 text-success' },
  email: { label: __('Email', 'yay-automation'), icon: Mail, className: 'bg-warning/15 text-warning-accent' },
};

/** Small tinted tile for one app. */
export function TemplateAppIcon({ app, className }: { app: WorkflowTemplateApp; className?: string }) {
  const { label, icon: Icon, className: tone } = appMeta[app];

  return (
    <span title={label} className={cn('flex size-7 items-center justify-center rounded-md', tone, className)}>
      <Icon className="size-4" />
      <span className="sr-only">{label}</span>
    </span>
  );
}
