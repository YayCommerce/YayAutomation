import { useState, type MouseEventHandler } from 'react';
import type { WorkflowPlugin, WorkflowTemplate } from '@yay-automation/types';
import { ChevronDown } from 'lucide-react';
import { Link, useLocation, useNavigate, useParams } from 'react-router';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button, LoadingButton } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { ReadOnlyCanvas } from '../../shared/canvas/ReadOnlyCanvas';
import { EditorLayout, EditorPlaceholder } from '../../shared/layout/EditorLayout';
import { LIBRARY_PATH, readLibraryNavState } from '../../shared/nav/library.nav';
import { PluginIcon, plugins } from '../../shared/ui/PluginIcon';
import { comingSoonLabel } from '../../steps/core/pending';
import { findTemplate, isComingSoon, pluginsOf } from '../../templates';
import { useCreateDraft } from './useCreateDraft';

export default function TemplatePreviewPage() {
  const { templateId = '' } = useParams();
  const template = findTemplate(templateId);
  const navigate = useNavigate();
  const location = useLocation();
  const { createDraft, isCreating } = useCreateDraft();

  // "More templates" returns to the open library. Opened from a card → pop that entry;
  // otherwise (bookmark, reload) → link. × leaves the flow for the workflow list (EditorLayout default).
  const toLibrary: MouseEventHandler<HTMLAnchorElement> = (event) => {
    if (readLibraryNavState(location.state).fromLibrary) {
      event.preventDefault();
      navigate(-1);
    }
  };

  if (!template) {
    return <EditorPlaceholder error={new Error(__('Template not found.', 'yay-automation'))} />;
  }

  return (
    <EditorLayout
      title={
        <>
          <span className="truncate font-medium">{template.name}</span>
          <Badge variant="primary-soft">{__('Template', 'yay-automation')}</Badge>
        </>
      }
      actions={
        isComingSoon(template.graph) ? (
          <Tooltip>
            <TooltipTrigger asChild>
              {/* Disabled buttons swallow pointer events, so a wrapper carries the tooltip. */}
              <span tabIndex={0}>
                <Button size="sm" disabled>
                  {__('Install', 'yay-automation')}
                </Button>
              </span>
            </TooltipTrigger>
            <TooltipContent>{comingSoonLabel}</TooltipContent>
          </Tooltip>
        ) : (
          <LoadingButton
            size="sm"
            loading={isCreating}
            disabled={isCreating}
            onClick={() => createDraft(template.name, template.graph)}
          >
            {__('Install', 'yay-automation')}
          </LoadingButton>
        )
      }
    >
      <div className="relative size-full">
        {/* The About panel covers the bottom-left corner, where the zoom controls usually sit. */}
        <ReadOnlyCanvas key={template.id} graph={template.graph} controlsPosition="bottom-right" maxZoom={2} />

        <Button variant="outline" size="sm" className="absolute top-4 right-4" asChild>
          <Link to={LIBRARY_PATH} onClick={toLibrary}>
            {__('More templates', 'yay-automation')}
          </Link>
        </Button>

        <AboutTemplate template={template} className="absolute bottom-4 left-4" />
      </div>
    </EditorLayout>
  );
}

function AboutTemplate({ template, className }: { template: WorkflowTemplate; className?: string }) {
  const [open, setOpen] = useState(true);

  return (
    <Collapsible
      open={open}
      onOpenChange={setOpen}
      className={cn('bg-card text-card-foreground w-96 max-w-[calc(100%-2rem)] rounded-lg border shadow-md', className)}
    >
      <CollapsibleTrigger className="flex w-full cursor-pointer items-center justify-between gap-2 px-4 py-3 text-sm font-semibold">
        {__('About this template', 'yay-automation')}
        <ChevronDown className={cn('size-4 transition-transform', open && 'rotate-180')} aria-hidden />
      </CollapsibleTrigger>
      <CollapsibleContent>
        <div className="flex flex-col gap-4 px-4 pb-4">
          <p className="text-muted-foreground text-sm">{template.description}</p>
          <section>
            <h3 className="text-sm font-semibold">{__('Plugins used', 'yay-automation')}</h3>
            {/* Gap, not `space-y-*`: WP admin's `li { margin-bottom }` beats its zero-specificity margins. */}
            <ul className="mt-2 flex flex-col gap-2">
              {pluginsOf(template.graph).map((plugin) => (
                <li key={plugin} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                  <PluginIcon plugin={plugin} />
                  <span className="min-w-0 flex-1 truncate font-medium">{plugins[plugin].label}</span>
                  <InstallState plugin={plugin} />
                </li>
              ))}
            </ul>
          </section>
        </div>
      </CollapsibleContent>
    </Collapsible>
  );
}

function InstallState({ plugin }: { plugin: WorkflowPlugin }) {
  return plugins[plugin].active ? (
    <Badge variant="dimmed">{__('Installed', 'yay-automation')}</Badge>
  ) : (
    <Badge variant="warning-soft">{__('Not installed', 'yay-automation')}</Badge>
  );
}
