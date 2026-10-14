import { useMemo, useState, type ReactNode } from 'react';
import type { Workflow, WorkflowIssue } from '@yay-automation/types';
import { ChevronLeft, CircleAlert, Pencil } from 'lucide-react';
import { Link, useNavigate, useParams } from 'react-router';
import { __, _n, sprintf } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useWorkflowQuery } from '../../api/workflow.query';
import { activateIssues, stepsNeedingSetup } from '../../model/validation';
import { useActivateWorkflow } from '../../shared/actions/useActivateWorkflow';
import { ActivateDialog, DeactivateDialog, MoreActionsMenu } from '../../shared/actions/WorkflowActions';
import { ReadOnlyCanvas } from '../../shared/canvas/ReadOnlyCanvas';
import { WorkflowAppIcon } from '../../shared/layout/EditorLayout';
import type { EditorNavState } from '../../shared/nav/editor.nav';
import { ErrorAlert } from '../../shared/ui/ErrorAlert';
import { workflowState, WorkflowStatusBadge } from '../../shared/ui/WorkflowStatusBadge';
import { plainSummary, stepOf, SummaryText } from '../../steps';
import { StepTile } from '../../steps/core/StepTile';
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
  const setupIssues = refused?.workflow === workflow ? refused.issues : issues;

  // The workflow as it runs (or as saved), then draft changes when there are any.
  const tabs: { value: string; label: string; graph: Workflow['graph'] }[] = [
    { value: 'live', label: __('Workflow', 'yay-automation'), graph: workflow.graph },
    ...(workflow.draft
      ? [{ value: 'draft', label: __('Draft changes', 'yay-automation'), graph: workflow.draft }]
      : []),
  ];
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
            <Button variant="destructive-outline" size="sm" onClick={() => setDeactivateOpen(true)}>
              {__('Deactivate', 'yay-automation')}
            </Button>
          ) : (
            // Not offered while it has issues; the notice below says what to fix.
            issues.length === 0 && (
              <Button size="sm" onClick={() => setActivateOpen(true)}>
                {__('Activate', 'yay-automation')}
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
      />
    </OverviewLayout>
  );
}

/**
 * Why the workflow can't be activated: "2 steps need setup before this workflow can run.", then
 * each issue in its own words (the server may report ones the editor doesn't show). "Fix in
 * editor" opens the editor showing them.
 */
function SetupNotice({ issues, onFix }: { issues: WorkflowIssue[]; onFix: () => void }) {
  const steps = stepsNeedingSetup(issues);
  const messages = [...new Set(issues.map((issue) => issue.message))];
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
                'yay-automation',
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
      <Button variant="destructive-soft" size="sm" onClick={onFix}>
        {__('Fix in editor', 'yay-automation')}
      </Button>
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
