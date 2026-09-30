import { useState, type MouseEventHandler } from 'react';
import type { WorkflowTemplate, WorkflowTemplateApp } from '@yay-automation/types';
import { ChevronDown } from 'lucide-react';
import { Link, useLocation, useNavigate, useParams } from 'react-router';
import { __ } from '@wordpress/i18n';

import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button, LoadingButton } from '@/components/ui/button';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { EditorLayout, EditorPlaceholder, type EditorCloseProps } from './editor/EditorLayout';
import { ReadOnlyCanvas } from './editor/ReadOnlyCanvas';
import { useCreateDraft } from './editor/useCreateDraft';
import { LIBRARY_PATH, readLibraryNavState } from './templates/library.nav';
import { appMeta, TemplateAppIcon } from './templates/TemplateApps';
import { findTemplate } from './templates/templates';

export default function TemplatePreviewPage() {
  const { templateId = '' } = useParams();
  const template = findTemplate(templateId);
  const navigate = useNavigate();
  const location = useLocation();
  const { createDraft, isCreating } = useCreateDraft();

  // × and "More templates" return to the open library. Opened from a card → pop that entry;
  // otherwise (bookmark, reload) → link.
  const toLibrary: MouseEventHandler<HTMLAnchorElement> = (event) => {
    if (readLibraryNavState(location.state).fromLibrary) {
      event.preventDefault();
      navigate(-1);
    }
  };
  const closeProps: EditorCloseProps = {
    closeTo: LIBRARY_PATH,
    closeLabel: __('Back to templates', 'yay-automation'),
    onClose: toLibrary,
  };

  if (!template) {
    return <EditorPlaceholder error={new Error(__('Template not found.', 'yay-automation'))} {...closeProps} />;
  }

  return (
    <EditorLayout
      {...closeProps}
      title={
        <>
          <span className="truncate font-medium">{template.name}</span>
          <Badge variant="primary-soft">{__('Template', 'yay-automation')}</Badge>
        </>
      }
      actions={
        <LoadingButton
          size="sm"
          loading={isCreating}
          disabled={isCreating}
          onClick={() => createDraft(template.name, template.graph)}
        >
          {__('Install', 'yay-automation')}
        </LoadingButton>
      }
    >
      <div className="relative size-full">
        {/* The About panel covers the bottom-left corner, where the zoom controls usually sit. */}
        <ReadOnlyCanvas key={template.id} graph={template.graph} controlsPosition="bottom-right" />

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
            <h3 className="text-sm font-semibold">{__('Apps used', 'yay-automation')}</h3>
            <ul className="mt-2 space-y-2">
              {template.apps.map((app) => (
                <li key={app} className="flex items-center gap-2 rounded-md border px-3 py-2 text-sm">
                  <TemplateAppIcon app={app} />
                  <span className="min-w-0 flex-1 truncate font-medium">{appMeta[app].label}</span>
                  <InstallState app={app} />
                </li>
              ))}
            </ul>
          </section>
        </div>
      </CollapsibleContent>
    </Collapsible>
  );
}

/** WooCommerce is the only app that can be missing; the others ship with WordPress or this plugin. */
function InstallState({ app }: { app: WorkflowTemplateApp }) {
  if (app === 'woocommerce' && !window.yayAutomationMeta.wc.active) {
    return <Badge variant="warning-soft">{__('Not installed', 'yay-automation')}</Badge>;
  }
  return <Badge variant="dimmed">{__('Installed', 'yay-automation')}</Badge>;
}
