import type { ReactNode } from 'react';
import { ArrowUp, ChevronRight, LayoutTemplate, Loader2, Sparkles } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { WorkflowAppIcon } from '../../shared/layout/EditorLayout';
import { ComingSoonChip } from '../../shared/ui/ComingSoonChip';
import { StepTile } from '../../steps/core/StepTile';

/** Example prompts for the describe box; shown, not usable, until the assistant ships (v2.0). */
const ideas = [
  __('Thank first-time buyers', 'yay-automation'),
  __('Alert me about big orders', 'yay-automation'),
  __('Remind shoppers about their cart', 'yay-automation'),
  __('Ask for a review after delivery', 'yay-automation'),
];

/**
 * Near full-screen start page: the question, a describe box with example prompts (the assistant;
 * "Coming soon" until it ships), then two ways to start on your own: browse templates, or choose
 * a trigger.
 */
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
          <DialogTitle className="text-sm font-medium">{__('New workflow', 'yay-automation')}</DialogTitle>
        </div>

        <div className="flex flex-1 flex-col items-center justify-center gap-4.5 overflow-y-auto px-6 pt-12 pb-14">
          <div className="text-center">
            <h2 className="text-[22px] font-semibold tracking-tight">
              {__('What do you want to automate?', 'yay-automation')}
            </h2>
            <DialogDescription className="text-muted-foreground mt-1 text-[13.5px]">
              {__(
                'Soon you can describe it and get a draft to review. Until then, start on your own below.',
                'yay-automation',
              )}
            </DialogDescription>
          </div>

          {/* The assistant isn't built yet: the box looks as it will, but nothing in it can be used. */}
          <div
            aria-disabled
            className="bg-card border-input flex w-full max-w-150 cursor-not-allowed flex-col gap-2.5 rounded-[14px] border pt-3 pr-3 pb-2.5 pl-3.5 shadow-[0_0_0_4px_color-mix(in_srgb,var(--primary)_10%,transparent),0_8px_24px_-14px_color-mix(in_srgb,var(--primary)_60%,transparent)]"
          >
            <div className="flex min-h-11 gap-2.5 text-[14.5px]/normal">
              <Sparkles className="text-primary mt-0.75 size-4 shrink-0" aria-hidden />
              <span className="text-muted-foreground">
                {__('e.g. Email customers who haven’t ordered in 90 days with a coupon', 'yay-automation')}
              </span>
            </div>
            <div className="flex items-center justify-end gap-2">
              <ComingSoonChip />
              <span
                className="bg-muted text-muted-foreground flex size-8 items-center justify-center rounded-full"
                aria-hidden
              >
                <ArrowUp className="size-3.75 stroke-[2.5]" />
              </span>
            </div>
          </div>

          {/* Examples of what to describe; not clickable until the assistant ships. */}
          <ul
            className="flex max-w-160 flex-wrap justify-center gap-2"
            aria-label={__('Example prompts', 'yay-automation')}
          >
            {ideas.map((idea) => (
              <li
                key={idea}
                className="bg-card text-muted-foreground-600 flex h-7.5 items-center gap-1.5 rounded-full border px-3 text-[13px]"
              >
                <Sparkles className="text-muted-foreground size-3.25" aria-hidden />
                {idea}
              </li>
            ))}
          </ul>

          <div className="text-muted-foreground flex w-full max-w-150 items-center gap-3 text-[12.5px]">
            <span className="bg-border h-px flex-1" />
            {__('or start on your own', 'yay-automation')}
            <span className="bg-border h-px flex-1" />
          </div>

          <div className="grid w-full max-w-150 gap-3 sm:grid-cols-2">
            <StartCard
              tile={
                <span className="bg-primary/14 text-primary flex size-7.5 shrink-0 items-center justify-center rounded-lg">
                  <LayoutTemplate className="size-4" />
                </span>
              }
              title={__('Browse templates', 'yay-automation')}
              description={__('Ready-made workflows to review and activate', 'yay-automation')}
              onClick={onBrowseTemplates}
            />
            <StartCard
              tile={<StepTile kind="trigger" size="md" />}
              title={__('Choose a trigger', 'yay-automation')}
              description={__('Build it step by step', 'yay-automation')}
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
