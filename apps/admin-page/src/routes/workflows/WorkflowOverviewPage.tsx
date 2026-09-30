import { useState, type ReactNode } from 'react';
import { ControlButton } from '@xyflow/react';
import type { Workflow } from '@yay-automation/types';
import { ChevronLeft, Pencil } from 'lucide-react';
import { Link, useNavigate, useParams } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { formatDateTime } from '@/lib/helpers/date.helper';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { EditorNavState } from './editor/editor.context';
import { WorkflowAppIcon } from './editor/EditorLayout';
import { ReadOnlyCanvas } from './editor/ReadOnlyCanvas';
import { ApiError } from './editor/workflow.api';
import { useWorkflowActionMutation, useWorkflowQuery } from './editor/workflow.query';
import { MoreActionsMenu, TurnOffDialog, TurnOnDialog } from './editor/WorkflowActions';
import { workflowState, WorkflowStatusBadge } from './editor/WorkflowStatusBadge';

export default function WorkflowOverviewPage() {
  const { workflowId = '' } = useParams();
  const { data: workflow, error } = useWorkflowQuery(workflowId);

  if (workflow) {
    return <WorkflowOverview workflow={workflow} />;
  }

  return (
    <OverviewLayout title={!error && <Skeleton className="h-5 w-48" />}>
      {error ? (
        <div className="text-muted-foreground flex size-full items-center justify-center">{error.message}</div>
      ) : (
        <Skeleton className="size-full" />
      )}
    </OverviewLayout>
  );
}

/** Read-only summary of a saved workflow: the live version, plus unapplied changes when there are any. */
function WorkflowOverview({ workflow }: { workflow: Workflow }) {
  const navigate = useNavigate();
  const editPath = `/workflows/${workflow.id}/edit`;
  const isActive = workflow.status === 'active';

  const [turnOnOpen, setTurnOnOpen] = useState(false);
  const activateMutation = useWorkflowActionMutation('activate');
  const deactivateMutation = useWorkflowActionMutation('deactivate');

  const turnOn = () =>
    activateMutation.mutate(
      { id: workflow.id },
      {
        onSuccess: () => {
          setTurnOnOpen(false);
          toast.success(__('Workflow turned on', 'yay-automation'));
        },
        onError: (error) => {
          setTurnOnOpen(false);
          // Always say why it didn't turn on. With issues, also open the editor, where they're fixed.
          toast.error(error.message, {
            description:
              error instanceof ApiError && error.issues.length
                ? error.issues.map((issue) => issue.message).join(' ')
                : undefined,
          });
          if (error instanceof ApiError && error.issues.length) {
            navigate(editPath, { state: { showIssues: true } satisfies EditorNavState });
          }
        },
      },
    );

  const [turnOffOpen, setTurnOffOpen] = useState(false);
  const turnOff = () =>
    deactivateMutation.mutate(
      { id: workflow.id },
      {
        onSuccess: () => {
          setTurnOffOpen(false);
          toast.success(__('Workflow turned off', 'yay-automation'));
        },
        onError: (error) => {
          setTurnOffOpen(false);
          toast.error(error.message);
        },
      },
    );

  // Active: the live version, plus unapplied edits when there are any. Off: its one version, called
  // "Saved workflow" once it has been turned on, "Draft" before that.
  const state = workflowState(workflow);
  const tabs: { value: string; label: string; graph: Workflow['graph'] }[] =
    state === 'active'
      ? [
          { value: 'live', label: __('Active workflow', 'yay-automation'), graph: workflow.graph },
          ...(workflow.draft ? [{ value: 'draft', label: __('Draft', 'yay-automation'), graph: workflow.draft }] : []),
        ]
      : [
          {
            value: 'saved',
            label: state === 'inactive' ? __('Saved workflow', 'yay-automation') : __('Draft', 'yay-automation'),
            graph: workflow.graph,
          },
        ];
  const [tab, setTab] = useState(tabs[0].value);
  // A tab can disappear (draft applied or discarded elsewhere); fall back to the first.
  const currentTab = tabs.some((t) => t.value === tab) ? tab : tabs[0].value;

  return (
    <OverviewLayout
      title={
        <>
          <span className="truncate font-medium">{workflow.name}</span>
          <WorkflowStatusBadge state={state} />
        </>
      }
      actions={
        <>
          <Button variant="outline" size="sm" asChild>
            <Link to={editPath}>
              <Pencil />
              {__('Edit', 'yay-automation')}
            </Link>
          </Button>
          <MoreActionsMenu workflow={workflow} />
          {isActive ? (
            <Button variant="destructive" size="sm" onClick={() => setTurnOffOpen(true)}>
              {__('Turn off workflow', 'yay-automation')}
            </Button>
          ) : (
            <Button size="sm" onClick={() => setTurnOnOpen(true)}>
              {__('Turn on workflow', 'yay-automation')}
            </Button>
          )}
        </>
      }
    >
      <div className="flex size-full flex-col gap-3 p-4">
        <p className="text-muted-foreground text-sm">
          {sprintf(
            /* translators: %s: date and time */
            __('Last updated on %s', 'yay-automation'),
            formatDateTime(workflow.updatedAt),
          )}
        </p>
        <Tabs
          value={currentTab}
          onValueChange={setTab}
          className="bg-card min-h-0 flex-1 gap-3 rounded-lg border p-3 shadow-xs"
        >
          <TabsList>
            {tabs.map((t) => (
              <TabsTrigger key={t.value} value={t.value}>
                {t.label}
              </TabsTrigger>
            ))}
          </TabsList>
          {tabs.map((t) => (
            <TabsContent key={t.value} value={t.value} className="min-h-0 overflow-hidden rounded-md">
              <ReadOnlyCanvas
                key={t.value}
                graph={t.graph}
                controls={
                  <ControlButton
                    onClick={() => navigate(editPath)}
                    title={__('Edit workflow', 'yay-automation')}
                    aria-label={__('Edit workflow', 'yay-automation')}
                  >
                    <Pencil />
                  </ControlButton>
                }
              />
            </TabsContent>
          ))}
        </Tabs>
      </div>

      <TurnOnDialog
        open={turnOnOpen}
        onOpenChange={setTurnOnOpen}
        onConfirm={turnOn}
        pending={activateMutation.isPending}
      />
      <TurnOffDialog
        open={turnOffOpen}
        onOpenChange={setTurnOffOpen}
        onConfirm={turnOff}
        pending={deactivateMutation.isPending}
      />
    </OverviewLayout>
  );
}

/** Header ("<" back to the list, app icon, title, actions) above a full-height body. */
function OverviewLayout({ title, actions, children }: { title: ReactNode; actions?: ReactNode; children: ReactNode }) {
  return (
    // Fill the viewport below the WP admin bar (32px) and plugin header (h-13.5).
    <div className="flex h-[calc(100vh-32px-3.375rem)] flex-col">
      <div className="bg-background flex h-12 shrink-0 items-center gap-2 border-y px-4">
        <Button variant="ghost" size="icon-sm" asChild>
          <Link to="/workflows" aria-label={__('Back to workflows', 'yay-automation')}>
            <ChevronLeft />
          </Link>
        </Button>
        <WorkflowAppIcon />
        <span className="text-muted-foreground" aria-hidden>
          /
        </span>
        <div className="flex min-w-0 flex-1 items-center gap-2">{title}</div>
        {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
      </div>
      <div className="min-h-0 flex-1">{children}</div>
    </div>
  );
}
