import { useState, type ReactNode } from 'react';
import type { Workflow } from '@yaymatik/types';
import { CircleAlert } from 'lucide-react';
import { Link, useNavigate } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { formatDateTime } from '@/lib/helpers/date.helper';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Pagination } from '@/components/ui/pagination';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { activateIssues } from '../../model/validation';
import { WarningBadge } from '../../shared/ui/WarningBadge';
import { appBadge, workflowState, WorkflowStatusBadge, type WorkflowState } from '../../shared/ui/WorkflowStatusBadge';
import { plainSummary, stepOf } from '../../steps';
import { StepTile } from '../../steps/core/StepTile';
import { WorkflowBulkActions } from './WorkflowBulkActions';
import { WorkflowRowDialogs, WorkflowRowMenu, type RowTarget } from './WorkflowRowMenu';

const PAGE_SIZE = 10;

type Tab = 'all' | WorkflowState;

/** The live trigger's tile and name; its summary on hover. */
function TriggerCell({ workflow }: { workflow: Workflow }) {
  const trigger = workflow.graph.nodes.find((node) => node.kind === 'trigger');
  const stepDef = trigger && stepOf(trigger.data);
  if (!stepDef) return <span className="text-muted-foreground">{__('No trigger yet', 'yaymatik')}</span>;
  return (
    <span className="flex items-center gap-2" title={plainSummary(stepDef.summary(trigger.data.config))}>
      <StepTile kind="trigger" icon={stepDef.icon} size="sm" />
      {stepDef.label}
    </span>
  );
}

/** Shown in the run columns. */
const noRuns = <span className="text-muted-foreground">—</span>;

function TableHeadRow({ select }: { select: ReactNode }) {
  return (
    <TableRow>
      <TableHead className="w-9">{select}</TableHead>
      <TableHead>{__('Name', 'yaymatik')}</TableHead>
      <TableHead>{__('Status', 'yaymatik')}</TableHead>
      <TableHead>{__('Trigger', 'yaymatik')}</TableHead>
      <TableHead className="text-right">{__('Runs (30 days)', 'yaymatik')}</TableHead>
      <TableHead>{__('Last run', 'yaymatik')}</TableHead>
      <TableHead>{__('Last updated', 'yaymatik')}</TableHead>
      <TableHead className="w-12">
        <span className="sr-only">{__('Actions', 'yaymatik')}</span>
      </TableHead>
    </TableRow>
  );
}

/** A small segmented tab bar: the kit's `Segmented`, made compact. */
const tabBarClass = 'mb-3 h-auto gap-0.5 rounded-lg p-[3px]';
const tabClass = cn(
  'h-auto rounded-md border-0 px-2.5 py-0.75 text-[13px] font-normal data-[shape=square]:rounded-md',
  'text-muted-foreground-600 dark:text-muted-foreground-600 hover:text-foreground hover:bg-transparent',
  'data-[state=on]:bg-card dark:data-[state=on]:bg-card data-[state=on]:text-card-foreground dark:data-[state=on]:text-card-foreground',
  'data-[state=on]:font-medium data-[state=on]:shadow-[0_1px_2px_#0000001a]',
);

/**
 * The workflows list: All / Active / Inactive / Draft tabs, selectable rows, 10 per page, and bulk actions
 * for 2+ selected. Selection belongs to a tab (switching clears it) and survives paging.
 */
export function WorkflowsTable({ workflows }: { workflows: Workflow[] }) {
  const navigate = useNavigate();
  const [tab, setTab] = useState<Tab>('all');
  const [page, setPage] = useState(0);
  const [selectedIds, setSelectedIds] = useState<ReadonlySet<string>>(new Set());
  // Here, not in the row: the dialog must outlive a refresh that moves its row off this tab or page.
  const [rowTarget, setRowTarget] = useState<RowTarget | null>(null);
  const [rowDialogOpen, setRowDialogOpen] = useState(false);

  // Draft = never activated; Inactive = activated before, now off.
  const inTab = tab === 'all' ? workflows : workflows.filter((workflow) => workflowState(workflow) === tab);
  const pageCount = Math.max(1, Math.ceil(inTab.length / PAGE_SIZE));
  // Deletes can shrink the list under the current page.
  const pageIndex = Math.min(page, pageCount - 1);
  const rows = inTab.slice(pageIndex * PAGE_SIZE, (pageIndex + 1) * PAGE_SIZE);
  // Only what's still listed: a refresh can drop a selected workflow.
  const selected = inTab.filter((workflow) => selectedIds.has(workflow.id));

  const allChecked = selected.length > 0 && selected.length === inTab.length;
  const someChecked = selected.length > 0 && !allChecked;

  const toggle = (id: string, checked: boolean) =>
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (checked) next.add(id);
      else next.delete(id);
      return next;
    });
  const clearSelection = () => setSelectedIds(new Set());

  const headerCheckbox = (
    <Checkbox
      checked={allChecked ? true : someChecked ? 'indeterminate' : false}
      disabled={!inTab.length}
      // The kit draws a check for "indeterminate" too (YayUiKits follow-up); a lighter fill keeps "some
      // selected" apart from "all selected".
      className="data-[state=indeterminate]:border-primary data-[state=indeterminate]:bg-primary/40 data-[state=indeterminate]:text-primary-foreground"
      onCheckedChange={() => setSelectedIds(allChecked ? new Set() : new Set(inTab.map((workflow) => workflow.id)))}
      aria-label={__('Select all workflows', 'yaymatik')}
    />
  );

  return (
    <>
      {/* Tabs and table share one card. */}
      <div className="bg-card rounded-[10px] border p-3">
        <Segmented
          value={tab}
          onValueChange={(value) => {
            if (!value) return;
            setTab(value as Tab);
            setPage(0);
            clearSelection();
          }}
          shape="square"
          className={tabBarClass}
        >
          <SegmentedItem value="all" className={tabClass}>
            {__('All', 'yaymatik')}
          </SegmentedItem>
          <SegmentedItem value="active" className={tabClass}>
            {__('Active', 'yaymatik')}
          </SegmentedItem>
          <SegmentedItem value="inactive" className={tabClass}>
            {__('Inactive', 'yaymatik')}
          </SegmentedItem>
          <SegmentedItem value="draft" className={tabClass}>
            {__('Draft', 'yaymatik')}
          </SegmentedItem>
        </Segmented>

        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableHeadRow select={headerCheckbox} />
            </TableHeader>
            <TableBody>
              {rows.length === 0 && (
                <TableRow>
                  <TableCell colSpan={8} className="text-muted-foreground h-24 text-center">
                    {__('No workflows here yet.', 'yaymatik')}
                  </TableCell>
                </TableRow>
              )}
              {rows.map((workflow) => (
                <TableRow
                  key={workflow.id}
                  className="cursor-pointer"
                  // The whole row opens the Overview. The name link keeps keyboard and middle-click access;
                  // the link, the whole checkbox cell and the "⋯" menu handle their own clicks. Clicks
                  // from portaled content (the menu) bubble here through React, so they must be in the row.
                  onClick={(event) => {
                    const target = event.target as HTMLElement;
                    if (event.currentTarget.contains(target) && !target.closest('a, button, [data-row-select]')) {
                      navigate(`/workflows/${workflow.id}`);
                    }
                  }}
                >
                  {/* A near miss on the checkbox mustn't open the Overview and lose the selection. */}
                  <TableCell data-row-select>
                    <Checkbox
                      checked={selectedIds.has(workflow.id)}
                      onCheckedChange={(checked) => toggle(workflow.id, checked === true)}
                      aria-label={sprintf(
                        /* translators: %s: workflow name */
                        __('Select %s', 'yaymatik'),
                        workflow.name,
                      )}
                    />
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center gap-2">
                      <Link to={`/workflows/${workflow.id}`} className="hover:text-primary font-medium">
                        {workflow.name}
                      </Link>
                      {workflow.draft && <WarningBadge>{__('Draft changes', 'yaymatik')}</WarningBadge>}
                    </div>
                  </TableCell>
                  <TableCell>
                    <div className="flex items-center gap-1.5">
                      <WorkflowStatusBadge state={workflowState(workflow)} />
                      {activateIssues(workflow).length > 0 && <NeedsSetupBadge />}
                    </div>
                  </TableCell>
                  <TableCell>
                    <TriggerCell workflow={workflow} />
                  </TableCell>
                  <TableCell className="text-right tabular-nums">{noRuns}</TableCell>
                  <TableCell>{noRuns}</TableCell>
                  <TableCell className="text-muted-foreground">{formatDateTime(workflow.lastVersionAt)}</TableCell>
                  <TableCell>
                    <WorkflowRowMenu
                      workflow={workflow}
                      onAction={(action) => {
                        setRowTarget({ action, workflow });
                        setRowDialogOpen(true);
                      }}
                    />
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
      </div>

      {/* Always mounted: a bulk action's dialog must survive the list refresh that can empty the
          selection (e.g. the "couldn't delete" alert after a partly refused delete). The bar hides
          itself below 2 selected. */}
      <div
        className={cn(
          'flex flex-col items-center gap-3 sm:flex-row',
          (selected.length > 1 || inTab.length > PAGE_SIZE) && 'mt-4',
        )}
      >
        <WorkflowBulkActions
          selected={selected}
          onClear={clearSelection}
          onDone={(keepIds) => setSelectedIds(new Set(keepIds))}
        />
        {inTab.length > PAGE_SIZE && (
          <Pagination
            pageIndex={pageIndex}
            pageCount={pageCount}
            onPreviousPage={() => setPage(pageIndex - 1)}
            onNextPage={() => setPage(pageIndex + 1)}
            onPageChange={setPage}
            canPreviousPage={pageIndex > 0}
            canNextPage={pageIndex < pageCount - 1}
            className="text-sm sm:ms-auto"
          />
        )}
      </div>

      <WorkflowRowDialogs target={rowTarget} open={rowDialogOpen} onClose={() => setRowDialogOpen(false)} />
    </>
  );
}

export function WorkflowsTableSkeleton() {
  return (
    <div className="bg-card overflow-x-auto rounded-[10px] border p-3">
      <Table>
        <TableHeader>
          <TableHeadRow select={null} />
        </TableHeader>
        <TableBody>
          {Array.from({ length: 3 }, (_, i) => (
            <TableRow key={i}>
              {Array.from({ length: 8 }, (_, j) => (
                <TableCell key={j}>
                  <Skeleton className="h-5" />
                </TableCell>
              ))}
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  );
}

/**
 * Next to a Draft or Inactive status when the server's issues block Activate. Looks like a step
 * card's "Needs setup" pill (solid warning, alert icon), not like "Draft changes".
 */
function NeedsSetupBadge() {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <Badge variant="secondary" className={cn(appBadge, 'bg-warning text-warning-foreground font-semibold')}>
          <CircleAlert className="stroke-[2.5]" aria-hidden />
          {__('Needs setup', 'yaymatik')}
        </Badge>
      </TooltipTrigger>
      <TooltipContent>{__('Fix the issues in the editor before activating.', 'yaymatik')}</TooltipContent>
    </Tooltip>
  );
}
