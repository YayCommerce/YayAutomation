import { useCallback, useMemo, useState, type ReactNode } from 'react';
import type { Workflow, WorkflowIssue } from '@yaymatik/types';
import { ChevronLeft, CircleAlert, Pencil } from 'lucide-react';
import { createPortal } from 'react-dom';
import { Link, useNavigate, useParams, useSearchParams } from 'react-router';
import { __, _n, sprintf } from '@wordpress/i18n';

import { shortDay } from '@/lib/helpers/date.helper';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useHeaderSlot } from '@/routes/layout/useHeaderSlot';
import { useWorkflowQuery } from '../../api/workflow.query';
import { activateIssues, activateIssueTexts, issueText, stepsNeedingSetup } from '../../model/validation';
import { useActivateWorkflow } from '../../shared/actions/useActivateWorkflow';
import { ActivateDialog, DeactivateDialog, MoreActionsMenu } from '../../shared/actions/WorkflowActions';
import { ReadOnlyCanvas } from '../../shared/canvas/ReadOnlyCanvas';
import { CreateWorkflowButton } from '../../shared/create/CreateWorkflowButton';
import { WorkflowAppIcon } from '../../shared/layout/EditorLayout';
import type { EditorNavState } from '../../shared/nav/editor.nav';
import { ErrorAlert } from '../../shared/ui/ErrorAlert';
import { LastRun } from '../../shared/ui/RunStatus';
import { workflowState, WorkflowStatusBadge } from '../../shared/ui/WorkflowStatusBadge';
import { plainSummary, stepOf, SummaryText } from '../../steps';
import { StepTile } from '../../steps/core/StepTile';
import { RunDetailSheet } from './RunDetailSheet';
import { VersionHistory } from './VersionHistory';
import { WorkflowRuns } from './WorkflowRuns';

export default function WorkflowOverviewPage() {
  const { workflowId = '' } = useParams();
  const { data: workflow, error } = useWorkflowQuery(workflowId);
  const headerSlot = useHeaderSlot();

  return (
    <>
      {workflow ? (
        <WorkflowOverview workflow={workflow} />
      ) : (
        <OverviewLayout title={!error && <Skeleton className="h-5 w-48" />}>
          {error ? (
            <div className="text-muted-foreground flex flex-1 items-center justify-center">{error.message}</div>
          ) : (
            <Skeleton className="flex-1" />
          )}
        </OverviewLayout>
      )}
      {headerSlot && createPortal(<CreateWorkflowButton />, headerSlot)}
    </>
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

/** Read-only summary of a saved workflow: the live version, plus draft changes when there are any. */
function WorkflowOverview({ workflow }: { workflow: Workflow }) {
  const navigate = useNavigate();
  const editPath = `/workflows/${workflow.id}/edit`;
  const state = workflowState(workflow);
  const isActive = state === 'active';

  const { activate, deactivate, pending } = useActivateWorkflow(workflow);
  const [activateOpen, setActivateOpen] = useState(false);
  const [deactivateOpen, setDeactivateOpen] = useState(false);
  const issues = useMemo(() => activateIssues(workflow), [workflow]);
  // What a refused Activate reported: newer than this copy's issues when the workflow changed
  // elsewhere. Kept for this copy only: a refetch shows its own issues again.
  const [refused, setRefused] = useState<{ workflow: Workflow; issues: WorkflowIssue[] } | null>(null);
  const refusedNow = refused?.workflow === workflow ? refused.issues : null;
  const setupIssues = refusedNow ?? issues;
  // A refusal doesn't say which graph each issue is from: the live one is checked first, then the draft.
  const setupMessages = useMemo(
    () =>
      refusedNow
        ? [...new Set(refusedNow.map((issue) => issueText(issue, workflow.graph, workflow.draft)))]
        : activateIssueTexts(workflow),
    [refusedNow, workflow],
  );

  // The workflow as it runs (or as saved), then draft changes when there are any.
  const tabs: { value: string; label: string; graph: Workflow['graph'] }[] = [
    { value: 'live', label: __('Workflow', 'yaymatik-automation-for-woocommerce'), graph: workflow.graph },
    ...(workflow.draft
      ? [{ value: 'draft', label: __('Draft changes', 'yaymatik-automation-for-woocommerce'), graph: workflow.draft }]
      : []),
  ];
  const [tab, setTab] = useState(tabs[0].value);
  const [historyPage, setHistoryPage] = useState(1);
  // The open run is in the URL (`?run=<id>`), so a reload or a shared link opens it again.
  const [searchParams, setSearchParams] = useSearchParams();
  const runId = searchParams.get('run');
  // Replaced, not pushed, on open and close alike: Back leaves the overview instead of stepping through runs.
  const openRun = (id: string) =>
    setSearchParams(
      (params) => {
        params.set('run', id);
        return params;
      },
      { replace: true },
    );
  const closeRun = useCallback(
    () =>
      setSearchParams(
        (params) => {
          params.delete('run');
          return params;
        },
        { replace: true },
      ),
    [setSearchParams],
  );
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
              {__('Edit', 'yaymatik-automation-for-woocommerce')}
            </Link>
          </Button>
          <MoreActionsMenu workflow={workflow} />
          {isActive ? (
            <Button variant="destructive-outline" size="sm" onClick={() => setDeactivateOpen(true)}>
              {__('Deactivate', 'yaymatik-automation-for-woocommerce')}
            </Button>
          ) : (
            // Not offered while it has issues; the notice below says what to fix.
            issues.length === 0 && (
              <Button size="sm" onClick={() => setActivateOpen(true)}>
                {__('Activate', 'yaymatik-automation-for-woocommerce')}
              </Button>
            )
          )}
        </>
      }
    >
      <div className="flex flex-col gap-3.5 p-4">
        <SummaryRow workflow={workflow} />
        {!isActive && setupIssues.length > 0 && (
          <SetupNotice
            issues={setupIssues}
            messages={setupMessages}
            onFix={() => navigate(editPath, { state: { showIssues: true } satisfies EditorNavState })}
          />
        )}
        <Tabs value={currentTab} onValueChange={setTab} className="bg-card gap-3 rounded-lg border p-3 shadow-xs">
          <TabsList className="h-auto w-full justify-start gap-5 rounded-none border-b bg-transparent p-0 px-1">
            {tabs.map((t) => (
              <TabsTrigger key={t.value} value={t.value} className={underlineTabClass}>
                {t.label}
              </TabsTrigger>
            ))}
            <TabsTrigger value={HISTORY_TAB} className={underlineTabClass}>
              {__('Version history', 'yaymatik-automation-for-woocommerce')}
            </TabsTrigger>
          </TabsList>
          {tabs.map((t) => (
            <TabsContent key={t.value} value={t.value} className={canvasPanelClass}>
              <ReadOnlyCanvas
                key={t.value}
                graph={t.graph}
                controls={
                  <Button variant="outline" size="icon-sm" className="bg-card" asChild>
                    <Link to={editPath} aria-label={__('Edit workflow', 'yaymatik-automation-for-woocommerce')}>
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
        <WorkflowRuns key={workflow.id} workflowId={workflow.id} onOpen={openRun} />
      </div>
      <RunDetailSheet workflowId={workflow.id} runId={runId} onClose={closeRun} />

      <ActivateDialog
        open={activateOpen}
        onOpenChange={setActivateOpen}
        onConfirm={() =>
          void activate({
            onSuccess: () => setRefused(null),
            onIssues: (refusedIssues) => setRefused({ workflow, issues: refusedIssues }),
          }).finally(() => setActivateOpen(false))
        }
        pending={pending}
        name={workflow.name}
        hasDraft={!!workflow.draft}
      />
      <DeactivateDialog
        open={deactivateOpen}
        onOpenChange={setDeactivateOpen}
        onConfirm={() => void deactivate().finally(() => setDeactivateOpen(false))}
        pending={pending}
        name={workflow.name}
        waitingRuns={workflow.waitingRuns}
      />
    </OverviewLayout>
  );
}

/**
 * Why the workflow can't be activated: "2 steps need setup before this workflow can run.", then
 * each issue in its own words, after its step's name (`messages`; the server may report ones the
 * editor doesn't show). "Fix in editor" opens the editor showing them; cart tracking is fixed in
 * Settings instead.
 */
function SetupNotice({ issues, messages, onFix }: { issues: WorkflowIssue[]; messages: string[]; onFix: () => void }) {
  const steps = stepsNeedingSetup(issues);
  const isTracking = (issue: WorkflowIssue) => issue.code === 'cart_tracking_off';
  return (
    <ErrorAlert className="flex items-center gap-3 [&>svg]:translate-y-0">
      <CircleAlert />
      <div className="min-w-0 flex-1">
        {steps > 0 && (
          <span className="font-medium">
            {sprintf(
              /* translators: %d: number of steps */
              _n(
                '%d step needs setup before this workflow can run.',
                '%d steps need setup before this workflow can run.',
                steps,
                'yaymatik-automation-for-woocommerce',
              ),
              steps,
            )}
          </span>
        )}
        <ul className="mt-0.5 list-disc ps-4">
          {messages.map((message) => (
            <li key={message}>{message}</li>
          ))}
        </ul>
      </div>
      {issues.some(isTracking) && (
        <Button variant="destructive-soft" size="sm" asChild>
          <Link to="/settings">{__('Open Settings', 'yaymatik-automation-for-woocommerce')}</Link>
        </Button>
      )}
      {!issues.every(isTracking) && (
        <Button variant="destructive-soft" size="sm" onClick={onFix}>
          {__('Fix in editor', 'yaymatik-automation-for-woocommerce')}
        </Button>
      )}
    </ErrorAlert>
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

/** Status, trigger, runs in the last 30 days, last run. The run cells show "—" until it first runs. */
function SummaryRow({ workflow }: { workflow: Workflow }) {
  const state = workflowState(workflow);
  const status =
    state === 'active'
      ? workflow.activatedAt
        ? sprintf(
            /* translators: %s: date, e.g. "Oct 2" */
            __('Active since %s', 'yaymatik-automation-for-woocommerce'),
            shortDay(workflow.activatedAt),
          )
        : __('Active', 'yaymatik-automation-for-woocommerce')
      : state === 'inactive'
        ? __('Inactive', 'yaymatik-automation-for-woocommerce')
        : __('Draft', 'yaymatik-automation-for-woocommerce');
  const trigger = workflow.graph.nodes.find((node) => node.kind === 'trigger');
  const triggerStep = trigger && stepOf(trigger.data);
  const summary = triggerStep && trigger ? triggerStep.summary(trigger.data.config) : '';
  const none = <span className="text-muted-foreground font-normal">—</span>;

  return (
    <div className="bg-card grid grid-cols-2 rounded-lg border md:grid-cols-4 md:divide-x max-md:[&>*:nth-child(even)]:border-l max-md:[&>*:nth-child(n+3)]:border-t">
      <SummaryCell label={__('Status', 'yaymatik-automation-for-woocommerce')}>{status}</SummaryCell>
      <SummaryCell label={__('Trigger', 'yaymatik-automation-for-woocommerce')}>
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
      <SummaryCell label={__('Runs (30 days)', 'yaymatik-automation-for-woocommerce')}>
        {workflow.lastRunAt ? workflow.runs30d : none}
      </SummaryCell>
      <SummaryCell label={__('Last run', 'yaymatik-automation-for-woocommerce')}>
        {workflow.lastRunAt ? <LastRun workflow={workflow} className="min-w-0" /> : none}
      </SummaryCell>
    </div>
  );
}

/** Header ("<" back to the list, app icon, title, actions) pinned under the app header; the page scrolls. */
function OverviewLayout({ title, actions, children }: { title: ReactNode; actions?: ReactNode; children: ReactNode }) {
  return (
    // At least the viewport below the WP admin bar (--wp-admin--admin-bar--height) and plugin header (h-13.5), so a short body leaves no gap.
    <div className="flex min-h-[calc(100vh-var(--wp-admin--admin-bar--height,32px)-3.375rem)] flex-col">
      {/* Same top offsets as the app header, plus its height; z-30 slides under its z-40. */}
      <div className="bg-background sticky top-13.5 z-30 flex h-12 shrink-0 items-center gap-2 border-b px-4 min-[601px]:top-[calc(var(--wp-admin--admin-bar--height,32px)+3.375rem)]">
        <Button variant="ghost" size="icon-sm" asChild>
          <Link to="/workflows" aria-label={__('Back to workflows', 'yaymatik-automation-for-woocommerce')}>
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
      <div className="flex flex-1 flex-col">{children}</div>
    </div>
  );
}
