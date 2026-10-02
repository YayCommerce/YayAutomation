import { useMemo, useState, type ReactNode } from 'react';
import type { Workflow } from '@yay-automation/types';
import { ChevronLeft, CircleAlert, Pencil } from 'lucide-react';
import { Link, useNavigate, useParams } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { formatDate, formatTime } from '@/lib/helpers/date.helper';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ApiError } from '../../api/workflow.api';
import { useWorkflowActionMutation, useWorkflowQuery } from '../../api/workflow.query';
import { validateWorkflow } from '../../model/validation';
import { MoreActionsMenu, TurnOffDialog, TurnOnDialog } from '../../shared/actions/WorkflowActions';
import { ReadOnlyCanvas } from '../../shared/canvas/ReadOnlyCanvas';
import { WorkflowAppIcon } from '../../shared/layout/EditorLayout';
import type { EditorNavState } from '../../shared/nav/editor.nav';
import { ErrorAlert } from '../../shared/ui/ErrorAlert';
import { onOffState, workflowState, WorkflowStatusBadge } from '../../shared/ui/WorkflowStatusBadge';
import { VersionHistory } from './VersionHistory';
import { WorkflowRuns } from './WorkflowRuns';

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

const HISTORY_TAB = 'history';

// Shopify-style pills: the selected tab is a gray pill, no card or shadow. Focus ring stays
// keyboard-only (the kit's `focus-visible` styles).
const pillTabClass =
  'flex-none rounded-full px-3 py-1.5 data-[state=active]:bg-foreground/10 data-[state=active]:text-foreground data-[state=active]:shadow-none dark:data-[state=active]:border-transparent dark:data-[state=active]:bg-foreground/10';

// Fixed height so React Flow has room to fit the graph. `flex-none` overrides the kit's `flex-1`
// (basis 0%): with no fixed card height that basis collapses the panel to 0px.
const canvasPanelClass = 'h-[clamp(280px,38vh,420px)] flex-none overflow-hidden rounded-md';

/** Read-only summary of a saved workflow: the live version, plus unapplied changes when there are any. */
function WorkflowOverview({ workflow }: { workflow: Workflow }) {
  const navigate = useNavigate();
  const editPath = `/workflows/${workflow.id}/edit`;
  const state = workflowState(workflow);
  const isActive = state === 'active';

  const [turnOnOpen, setTurnOnOpen] = useState(false);
  // Set by a Turn on that failed on issues; shows the error container until Turn on succeeds.
  const [turnOnFailed, setTurnOnFailed] = useState(false);
  const activateMutation = useWorkflowActionMutation('activate');
  const deactivateMutation = useWorkflowActionMutation('deactivate');

  const turnOn = () =>
    activateMutation.mutate(
      { id: workflow.id },
      {
        onSuccess: () => {
          setTurnOnOpen(false);
          setTurnOnFailed(false);
          toast.success(__('Workflow turned on', 'yay-automation'));
        },
        onError: (error) => {
          setTurnOnOpen(false);
          // Issues are explained by the error container, which leads to the editor; anything else is a toast.
          if (error instanceof ApiError && error.issues.length) setTurnOnFailed(true);
          else toast.error(error.message);
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

  // The running or saved content, then unapplied edits when there are any. A workflow never turned on
  // has just one version, and it's a draft.
  const liveLabel = {
    active: __('Active workflow', 'yay-automation'),
    inactive: __('Saved workflow', 'yay-automation'),
    draft: __('Draft', 'yay-automation'),
  }[state];
  const tabs: { value: string; label: string; graph: Workflow['graph'] }[] = [
    { value: 'live', label: liveLabel, graph: workflow.graph },
    ...(workflow.draft ? [{ value: 'draft', label: __('Draft', 'yay-automation'), graph: workflow.draft }] : []),
  ];
  // A saved workflow whose draft has issues can't be turned on (the server refuses too), so the
  // button isn't offered. An active workflow can always be turned off.
  const draftBlocksTurnOn = useMemo(
    () => !isActive && !!workflow.draft && validateWorkflow({ name: workflow.name, graph: workflow.draft }).length > 0,
    [isActive, workflow.draft, workflow.name],
  );
  const [tab, setTab] = useState(tabs[0].value);
  const [historyPage, setHistoryPage] = useState(1);
  // A tab can disappear (draft applied or discarded elsewhere); fall back to the first.
  const currentTab = tab === HISTORY_TAB || tabs.some((t) => t.value === tab) ? tab : tabs[0].value;

  return (
    <OverviewLayout
      title={
        <>
          <span className="truncate font-medium">{workflow.name}</span>
          <WorkflowStatusBadge state={onOffState(workflow)} />
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
            !draftBlocksTurnOn && (
              <Button size="sm" onClick={() => setTurnOnOpen(true)}>
                {__('Turn on workflow', 'yay-automation')}
              </Button>
            )
          )}
        </>
      }
    >
      <div className="flex flex-col gap-3 p-4">
        <p className="text-muted-foreground text-sm">
          {sprintf(
            /* translators: 1: date, 2: time */
            __('Last updated on %1$s at %2$s', 'yay-automation'),
            formatDate(workflow.lastVersionAt),
            formatTime(workflow.lastVersionAt),
          )}
        </p>
        {turnOnFailed && !isActive && (
          <ErrorAlert className="flex items-center gap-3 [&>svg]:translate-y-0">
            <CircleAlert />
            <p className="min-w-0 flex-1">
              <span className="font-medium">{__('Workflow contains errors', 'yay-automation')}</span>{' '}
              {__('Before you can turn on this workflow, you need to make some corrections.', 'yay-automation')}
            </p>
            <Button
              variant="destructive-soft"
              size="sm"
              onClick={() => navigate(editPath, { state: { showIssues: true } satisfies EditorNavState })}
            >
              {__('Edit workflow', 'yay-automation')}
            </Button>
          </ErrorAlert>
        )}
        <Tabs value={currentTab} onValueChange={setTab} className="bg-card gap-3 rounded-lg border p-3 shadow-xs">
          <TabsList className="h-auto bg-transparent p-0">
            {tabs.map((t) => (
              <TabsTrigger key={t.value} value={t.value} className={pillTabClass}>
                {t.label}
              </TabsTrigger>
            ))}
            <TabsTrigger value={HISTORY_TAB} className={pillTabClass}>
              {__('Version history', 'yay-automation')}
            </TabsTrigger>
          </TabsList>
          {tabs.map((t) => (
            <TabsContent key={t.value} value={t.value} className={canvasPanelClass}>
              <ReadOnlyCanvas
                key={t.value}
                graph={t.graph}
                controls={
                  <Button variant="outline" size="icon-sm" className="bg-card" asChild>
                    <Link to={editPath} aria-label={__('Edit workflow', 'yay-automation')}>
                      <Pencil />
                    </Link>
                  </Button>
                }
              />
            </TabsContent>
          ))}
          <TabsContent value={HISTORY_TAB}>
            <VersionHistory workflow={workflow} page={historyPage} onPageChange={setHistoryPage} />
          </TabsContent>
        </Tabs>
        {/* Run logging doesn't exist yet, so there are never runs to list. */}
        <WorkflowRuns runs={[]} />
      </div>

      <TurnOnDialog
        open={turnOnOpen}
        onOpenChange={setTurnOnOpen}
        onConfirm={turnOn}
        pending={activateMutation.isPending}
        hasDraft={!!workflow.draft}
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
      <div className="min-h-0 flex-1 overflow-y-auto">{children}</div>
    </div>
  );
}
