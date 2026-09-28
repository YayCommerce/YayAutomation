import type { WorkflowTemplate } from '@yay-automation/types';
import { Info } from 'lucide-react';
import { useLocation, useNavigate, useParams } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Badge } from '@/components/ui/badge';
import { Button, LoadingButton } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { toast } from '@/components/ui/sonner';
import { EditorLayout, EditorPlaceholder, type EditorBackProps } from './editor/EditorLayout';
import { ReadOnlyCanvas } from './editor/ReadOnlyCanvas';
import { useCreateWorkflowMutation } from './editor/workflow.query';
import { kindMeta } from './editor/WorkflowNodeCard';
import { LIBRARY_PATH, readLibraryNavState } from './templates/library.nav';
import { useTemplateQuery } from './templates/template.query';
import { appMeta, TemplateAppIcon } from './templates/TemplateApps';

export default function TemplatePreviewPage() {
  const { templateId = '' } = useParams();
  const { data: template, error } = useTemplateQuery(templateId);
  const navigate = useNavigate();
  const location = useLocation();
  const createMutation = useCreateWorkflowMutation();

  // Back returns to the open library. Opened from a card → pop that entry; otherwise (bookmark, reload) → link.
  const backProps: EditorBackProps = {
    backTo: LIBRARY_PATH,
    backLabel: __('Back to templates', 'yay-automation'),
    onBack: (event) => {
      if (readLibraryNavState(location.state).fromLibrary) {
        event.preventDefault();
        navigate(-1);
      }
    },
  };

  if (!template) {
    return <EditorPlaceholder error={error} {...backProps} />;
  }

  const applyTemplate = () =>
    createMutation.mutate(
      { name: template.name, graph: template.graph },
      {
        onSuccess: (workflow) => navigate(`/workflows/${workflow.id}`),
        onError: (err) => toast.error(err.message),
      },
    );

  return (
    <EditorLayout
      {...backProps}
      toolbar={
        <>
          <span className="truncate font-medium">{template.name}</span>
          <Badge variant="primary-soft">{__('Template', 'yay-automation')}</Badge>
          <div className="ml-auto flex shrink-0 items-center gap-2">
            {/* Below md the side panel is hidden; the same details open in a sheet instead. */}
            <Sheet>
              <SheetTrigger asChild>
                <Button variant="outline" size="sm" className="md:hidden">
                  <Info />
                  {__('Details', 'yay-automation')}
                </Button>
              </SheetTrigger>
              <SheetContent aria-describedby={undefined}>
                <SheetHeader>
                  <SheetTitle>{__('Template details', 'yay-automation')}</SheetTitle>
                </SheetHeader>
                <div className="min-h-0 flex-1 overflow-y-auto p-5">
                  <TemplateDetails template={template} />
                </div>
              </SheetContent>
            </Sheet>
            <LoadingButton
              size="sm"
              loading={createMutation.isPending}
              disabled={createMutation.isPending}
              onClick={applyTemplate}
            >
              {__('Use template', 'yay-automation')}
            </LoadingButton>
          </div>
        </>
      }
    >
      <div className="flex size-full">
        <div className="min-w-0 flex-1">
          <ReadOnlyCanvas key={template.id} graph={template.graph} />
        </div>
        <aside className="bg-background w-80 shrink-0 overflow-y-auto border-l p-5 max-md:hidden">
          <TemplateDetails template={template} />
        </aside>
      </div>
    </EditorLayout>
  );
}

/** Details body. Rendered in the side panel (md+) and in the "Details" sheet (below md). */
function TemplateDetails({ template }: { template: WorkflowTemplate }) {
  return (
    <div className="space-y-6">
      <section>
        <h2 className="font-semibold">{__('About this template', 'yay-automation')}</h2>
        <p className="text-muted-foreground mt-2 text-sm">{template.description}</p>
      </section>

      <section>
        <h3 className="text-muted-foreground text-xs font-medium uppercase">{__('Category', 'yay-automation')}</h3>
        <Badge variant="secondary" className="mt-2">
          {template.category}
        </Badge>
      </section>

      <section>
        <h3 className="text-muted-foreground text-xs font-medium uppercase">{__('Apps', 'yay-automation')}</h3>
        <ul className="mt-2 space-y-2">
          {template.apps.map((app) => (
            <li key={app} className="flex items-center gap-2 text-sm">
              <TemplateAppIcon app={app} />
              {appMeta[app].label}
            </li>
          ))}
        </ul>
      </section>

      <section>
        <h3 className="text-muted-foreground text-xs font-medium uppercase">{__('Steps', 'yay-automation')}</h3>
        <ol className="mt-2 space-y-2">
          {template.graph.nodes.map((node) => {
            const { label, icon: Icon } = kindMeta[node.kind];
            return (
              <li key={node.id} className="flex items-start gap-2 text-sm">
                <Icon className="text-muted-foreground mt-0.5 size-4 shrink-0" aria-label={label} />
                {node.data.title}
              </li>
            );
          })}
        </ol>
      </section>
    </div>
  );
}
