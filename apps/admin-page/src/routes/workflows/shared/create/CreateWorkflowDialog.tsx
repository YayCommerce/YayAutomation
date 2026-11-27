import type { ReactNode } from 'react';
import { ChevronRight, LayoutTemplate, Loader2 } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { StepTile } from '../../steps/core/StepTile';
import { WorkflowAppIcon } from '../layout/EditorLayout';

/** Near full-screen start page: the question, then two ways to start: browse templates, or choose a trigger. */
export function CreateWorkflowDialog({
  open,
  onOpenChange,
  onSelectTrigger,
  onBrowseTemplates,
  creating,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSelectTrigger: () => void;
  onBrowseTemplates: () => void;
  creating: boolean;
}) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="flex h-[calc(100vh-2rem)] flex-col gap-0 p-0 outline-none sm:max-w-[calc(100vw-2rem)]"
        // Focus the dialog itself, not the first card: no ring on open; Tab reaches the cards.
        onOpenAutoFocus={(event) => {
          event.preventDefault();
          (event.currentTarget as HTMLElement).focus();
        }}
      >
        <div className="flex h-12 shrink-0 items-center gap-2 border-b px-4">
          <WorkflowAppIcon />
          <DialogTitle className="text-sm font-medium">
            {__('New workflow', 'yaymatik-automation-for-woocommerce')}
          </DialogTitle>
        </div>

        <div className="flex flex-1 flex-col items-center justify-center gap-6 overflow-y-auto px-6 pb-16">
          <div className="text-center">
            <h2 className="text-[22px] font-semibold tracking-tight">
              {__('What do you want to automate?', 'yaymatik-automation-for-woocommerce')}
            </h2>
            <DialogDescription className="text-muted-foreground mt-1 text-[13.5px]">
              {__('Start from a ready-made template, or build it step by step.', 'yaymatik-automation-for-woocommerce')}
            </DialogDescription>
          </div>

          <div className="grid w-full max-w-150 gap-3 sm:grid-cols-2">
            <StartCard
              tile={
                <span className="bg-primary/14 text-primary flex size-7.5 shrink-0 items-center justify-center rounded-lg">
                  <LayoutTemplate className="size-4" />
                </span>
              }
              title={__('Browse templates', 'yaymatik-automation-for-woocommerce')}
              description={__('Ready-made workflows to review and activate', 'yaymatik-automation-for-woocommerce')}
              onClick={onBrowseTemplates}
            />
            <StartCard
              tile={<StepTile kind="trigger" size="md" />}
              title={__('Choose a trigger', 'yaymatik-automation-for-woocommerce')}
              description={__('Build it step by step', 'yaymatik-automation-for-woocommerce')}
              busy={creating}
              onClick={onSelectTrigger}
            />
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}

function StartCard({
  tile,
  title,
  description,
  busy,
  onClick,
}: {
  tile: ReactNode;
  title: string;
  description: string;
  /** Creating the workflow: a spinner replaces the arrow, and a second click does nothing. */
  busy?: boolean;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      disabled={busy}
      aria-busy={busy}
      onClick={onClick}
      className="bg-card hover:border-primary focus-visible:ring-ring/50 flex cursor-pointer items-center gap-3 rounded-xl border px-3.5 py-3 text-left transition-colors outline-none focus-visible:ring-[3px] disabled:cursor-wait"
    >
      {tile}
      <span className="min-w-0 flex-1">
        <span className="block text-sm font-semibold">{title}</span>
        <span className="text-muted-foreground block text-[12.5px]">{description}</span>
      </span>
      {busy ? (
        <Loader2 className="text-muted-foreground size-4 shrink-0 animate-spin" aria-hidden />
      ) : (
        <ChevronRight className="text-muted-foreground size-4 shrink-0" aria-hidden />
      )}
    </button>
  );
}
