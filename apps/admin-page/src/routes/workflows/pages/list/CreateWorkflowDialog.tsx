import { ArrowUp, LayoutTemplate, Sparkles, Zap } from 'lucide-react';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { WorkflowAppIcon } from '../../shared/layout/EditorLayout';
import { CreateWorkflowIllustration } from './WorkflowIllustrations';

/** Round pill that tints with the primary color on hover. */
const startButton = 'hover:bg-primary/10 hover:text-primary rounded-full';

/**
 * Near full-screen start page, like Shopify Flow's: describe the workflow (the AI assistant; layout
 * only for now), or select a trigger, or browse the template library.
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
      <DialogContent className="flex h-[calc(100vh-2rem)] flex-col gap-0 p-0 sm:max-w-[calc(100vw-2rem)]">
        <div className="flex h-12 shrink-0 items-center gap-2 px-4">
          <WorkflowAppIcon />
          <DialogTitle className="text-sm font-medium">{__('Workflow', 'yay-automation')}</DialogTitle>
        </div>

        <div className="flex flex-1 flex-col items-center justify-center gap-5 px-4 pb-16">
          <CreateWorkflowIllustration />
          <DialogDescription className="sr-only">
            {__('Describe what you want to automate, or start on your own.', 'yay-automation')}
          </DialogDescription>

          {/* The assistant isn't built yet: the box shows where it goes. Disabled controls swallow
              pointer events, so the wrapper carries the tooltip. */}
          <Tooltip>
            <TooltipTrigger asChild>
              <div className="ring-primary/15 relative w-full max-w-md rounded-xl ring-4">
                <Sparkles
                  className="text-primary pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2"
                  aria-hidden
                />
                <Input
                  disabled
                  className="h-11 rounded-xl pr-11 pl-9"
                  placeholder={__('Tell me what you want to create…', 'yay-automation')}
                  aria-label={__('Describe your workflow', 'yay-automation')}
                />
                <Button
                  size="icon-sm"
                  variant="ghost"
                  className="absolute top-1/2 right-1.5 size-7 -translate-y-1/2 rounded-full"
                  disabled
                  aria-label={__('Send', 'yay-automation')}
                >
                  <ArrowUp />
                </Button>
              </div>
            </TooltipTrigger>
            {/* Above the dialog (z-100002); the kit's tooltip layer sits below it. */}
            <TooltipContent className="z-100003">{__('Coming soon', 'yay-automation')}</TooltipContent>
          </Tooltip>

          <div className="text-muted-foreground flex w-full max-w-60 items-center gap-3 text-sm">
            <span className="bg-border h-px flex-1" />
            {__('Or', 'yay-automation')}
            <span className="bg-border h-px flex-1" />
          </div>

          <div className="flex gap-2">
            <Button variant="secondary" size="sm" className={startButton} disabled={creating} onClick={onSelectTrigger}>
              <Zap />
              {__('Select a trigger', 'yay-automation')}
            </Button>
            <Button variant="secondary" size="sm" className={startButton} onClick={onBrowseTemplates}>
              <LayoutTemplate />
              {__('Template Library', 'yay-automation')}
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}
