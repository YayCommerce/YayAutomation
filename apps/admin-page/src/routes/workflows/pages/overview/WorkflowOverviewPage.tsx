import { useMemo, useState, type ReactNode } from 'react';
import type { Workflow, WorkflowIssue } from '@yay-automation/types';
import { ChevronLeft, CircleAlert, Pencil } from 'lucide-react';
import { Link, useNavigate, useParams } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ApiError } from '../../api/workflow.api';
import { useWorkflowActionMutation, useWorkflowQuery } from '../../api/workflow.query';
import { activateIssues } from '../../model/validation';
import { MoreActionsMenu, TurnOffDialog, TurnOnDialog } from '../../shared/actions/WorkflowActions';
import { ReadOnlyCanvas } from '../../shared/canvas/ReadOnlyCanvas';
import { WorkflowAppIcon } from '../../shared/layout/EditorLayout';
import type { EditorNavState } from '../../shared/nav/editor.nav';
import { ErrorAlert } from '../../shared/ui/ErrorAlert';
import { StepTile } from '../../shared/ui/StepTile';
import { workflowState, WorkflowStatusBadge } from '../../shared/ui/WorkflowStatusBadge';
import { plainSummary, stepOf, SummaryText } from '../../steps';
import { shortDay, VersionHistory } from './VersionHistory';
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

// Underline tabs: the selected tab gets a primary line under it, no fill. Focus ring stays
// keyboard-only (the kit's `focus-visible` styles).
const underlineTabClass =
  'text-muted-foreground data-[state=active]:text-foreground data-[state=active]:border-b-primary dark:data-[state=active]:border-b-primary -mb-px h-auto flex-none rounded-none border-0 border-b-2 border-transparent px-0 pt-0 pb-2 font-medium data-[state=active]:bg-transparent data-[state=active]:shadow-none dark:data-[state=active]:border-x-transparent dark:data-[state=active]:border-t-transparent dark:data-[state=active]:bg-transparent';

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
  // What a Turn on that failed on issues reported; shows the error container until Turn on succeeds.
  // Each message is listed: the server may find issues the editor doesn't check (e.g. a malformed tag).
  const [turnOnIssues, setTurnOnIssues] = useState<WorkflowIssue[]>([]);
  const activateMutation = useWorkflowActionMutation('activate');
  const deactivateMutation = useWorkflowActionMutation('deactivate');

  const turnOn = () =>
    activateMutation.mutate(
      { id: workflow.id },
      {
        onSuccess: () => {
          setTurnOnOpen(false);
          setTurnOnIssues([]);
          toast.success(__('Workflow activated', 'yay-automation'));
        },
        onError: (error) => {
          setTurnOnOpen(false);
          // Issues are explained by the error container, which leads to the editor; anything else is a toast.
          if (error instanceof ApiError && error.issues.length) setTurnOnIssues(error.issues);
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
          toast.success(__('Workflow deactivated', 'yay-automation'));
        },
        onError: (error) => {
          setTurnOffOpen(false);
          toast.error(error.message);
        },
      },
    );

  // The workflow as it runs (or as saved), then draft changes when there are any.
  const tabs: { value: string; label: string; graph: Workflow['graph'] }[] = [
    { value: 'live', label: __('Workflow', 'yay-automation'), graph: workflow.graph },
    ...(workflow.draft
      ? [{ value: 'draft', label: __('Draft changes', 'yay-automation'), graph: workflow.draft }]
      : []),
  ];
  // A draft with issues can't be activated, so the button isn't offered. Without a draft, Turn on
  // stays: a refusal opens the error container that explains the issues.
  const draftBlocksTurnOn = useMemo(() => !!workflow.draft && activateIssues(workflow).length > 0, [workflow]);
  const [tab, setTab] = useState(tabs[0].value);
  const [historyPage, setHistoryPage] = useState(1);
  // A tab can disappear (draft applied or discarded elsewhere); fall back to the first.
  const currentTab = tab === HISTORY_TAB || tabs.some((t) => t.value === tab) ? tab : tabs[0].value;

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
            <Button variant="destructive-outline" size="sm" onClick={() => setTurnOffOpen(true)}>
              {__('Deactivate', 'yay-automation')}
            </Button>
          ) : (
            !draftBlocksTurnOn && (
              <Button size="sm" onClick={() => setTurnOnOpen(true)}>
                {__('Activate', 'yay-automation')}
              </Button>
            )
          )}
        </>
      }
    >
      <div className="flex flex-col gap-3.5 p-4">
        <SummaryRow workflow={workflow} />
        {turnOnIssues.length > 0 && !isActive && (
          <ErrorAlert className="flex items-center gap-3 [&>svg]:translate-y-0">
            <CircleAlert />
            <div className="min-w-0 flex-1">
              <span className="font-medium">{__('Workflow contains errors', 'yay-automation')}</span>{' '}
              {__('Before you can turn on this workflow, you need to make some corrections.', 'yay-automation')}
              <ul className="mt-1 list-disc ps-4">
                {[...new Set(turnOnIssues.map((issue) => issue.message))].map((message) => (
                  <li key={message}>{message}</li>
                ))}
              </ul>
            </div>
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
          <TabsList className="h-auto w-full justify-start gap-5 rounded-none border-b bg-transparent p-0 px-1">
            {tabs.map((t) => (
              <TabsTrigger key={t.value} value={t.value} className={underlineTabClass}>
                {t.label}
              </TabsTrigger>
            ))}
            <TabsTrigger value={HISTORY_TAB} className={underlineTabClass}>
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

/** One cell of the summary row: a small label over its value. */
function SummaryCell({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex min-w-0 flex-col gap-1 px-3.5 py-3">
      <span className="text-muted-foreground text-xs">{label}</span>
      <div className="flex min-w-0 items-center gap-2 font-semibold tabular-nums">{children}</div>
    </div>
  );
}

/**
 * Status, trigger, runs in the last 30 days, last run. The run cells show "—" until run history
 * (M2) fills them.
 */
function SummaryRow({ workflow }: { workflow: Workflow }) {
  const state = workflowState(workflow);
  const status =
    state === 'active'
      ? workflow.activatedAt
        ? sprintf(
            /* translators: %s: date, e.g. "Oct 2" */
            __('Active since %s', 'yay-automation'),
            shortDay(workflow.activatedAt),
          )
        : __('Active', 'yay-automation')
      : state === 'inactive'
        ? __('Inactive', 'yay-automation')
        : __('Draft', 'yay-automation');
  const trigger = workflow.graph.nodes.find((node) => node.kind === 'trigger');
  const triggerStep = trigger && stepOf(trigger.data);
  const summary = triggerStep && trigger ? triggerStep.summary(trigger.data.config) : '';
  const none = <span className="text-muted-foreground font-normal">—</span>;

  return (
    <div className="bg-card grid grid-cols-2 rounded-lg border md:grid-cols-4 md:divide-x max-md:[&>*:nth-child(even)]:border-l max-md:[&>*:nth-child(n+3)]:border-t">
      <SummaryCell label={__('Status', 'yay-automation')}>{status}</SummaryCell>
      <SummaryCell label={__('Trigger', 'yay-automation')}>
        {triggerStep ? (
          <>
            <StepTile kind="trigger" icon={triggerStep.icon} size="sm" />
            <span className="min-w-0" title={plainSummary(summary)}>
              <span className="block truncate">{triggerStep.label}</span>
              <span className="text-muted-foreground-600 [&_strong]:text-card-foreground block truncate text-xs font-normal">
                <SummaryText text={summary} />
              </span>
            </span>
          </>
        ) : (
          none
        )}
      </SummaryCell>
      <SummaryCell label={__('Runs (30 days)', 'yay-automation')}>{none}</SummaryCell>
      <SummaryCell label={__('Last run', 'yay-automation')}>{none}</SummaryCell>
    </div>
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
