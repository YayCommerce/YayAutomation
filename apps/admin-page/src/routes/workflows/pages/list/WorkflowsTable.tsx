import { useState, type ReactNode } from 'react';
import type { Workflow } from '@yay-automation/types';
import { Trash2 } from 'lucide-react';
import { Link, useNavigate } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { formatDateTime } from '@/lib/helpers/date.helper';
import { cn } from '@/lib/utils';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Pagination } from '@/components/ui/pagination';
import { Segmented, SegmentedItem } from '@/components/ui/segmented';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { blockOf, SummaryText } from '../../blocks';
import { DeleteWorkflowDialog } from '../../shared/actions/WorkflowActions';
import { onOffState, WorkflowStatusBadge } from '../../shared/ui/WorkflowStatusBadge';
import { WorkflowBulkActions } from './WorkflowBulkActions';

const PAGE_SIZE = 10;

type Tab = 'all' | 'active' | 'inactive';

function triggerSummary(workflow: Workflow) {
  const trigger = workflow.graph.nodes.find((node) => node.kind === 'trigger');
  const block = trigger && blockOf(trigger.data);
  return block ? <SummaryText text={block.summary(trigger.data.config)} /> : '—';
}

function TableHeadRow({ select }: { select: ReactNode }) {
  return (
    <TableRow>
      <TableHead className="w-9">{select}</TableHead>
      <TableHead>{__('Name', 'yay-automation')}</TableHead>
      <TableHead>{__('Status', 'yay-automation')}</TableHead>
      <TableHead>{__('Trigger', 'yay-automation')}</TableHead>
      <TableHead>{__('Last updated', 'yay-automation')}</TableHead>
      <TableHead className="w-12">
        <span className="sr-only">{__('Actions', 'yay-automation')}</span>
      </TableHead>
    </TableRow>
  );
}

/**
 * The workflows list: All / Active / Inactive tabs, selectable rows, 10 per page, and bulk actions
 * for 2+ selected. Selection belongs to a tab (switching clears it) and survives paging.
 */
export function WorkflowsTable({ workflows }: { workflows: Workflow[] }) {
  const navigate = useNavigate();
  const [tab, setTab] = useState<Tab>('all');
  const [page, setPage] = useState(0);
  const [selectedIds, setSelectedIds] = useState<ReadonlySet<string>>(new Set());
  const [pendingDelete, setPendingDelete] = useState<Workflow | null>(null);

  // Never-turned-on workflows count as Inactive, like their badge.
  const inTab = tab === 'all' ? workflows : workflows.filter((workflow) => onOffState(workflow) === tab);
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
      aria-label={__('Select all workflows', 'yay-automation')}
    />
  );

  return (
    <>
      <Segmented
        value={tab}
        onValueChange={(value) => {
          if (!value) return;
          setTab(value as Tab);
          setPage(0);
          clearSelection();
        }}
        className="mb-4"
      >
        <SegmentedItem value="all">{__('All', 'yay-automation')}</SegmentedItem>
        <SegmentedItem value="active">{__('Active', 'yay-automation')}</SegmentedItem>
        <SegmentedItem value="inactive">{__('Inactive', 'yay-automation')}</SegmentedItem>
      </Segmented>

      <div className="overflow-x-auto rounded-lg border">
        <Table>
          <TableHeader>
            <TableHeadRow select={headerCheckbox} />
          </TableHeader>
          <TableBody>
            {rows.length === 0 && (
              <TableRow>
                <TableCell colSpan={6} className="text-muted-foreground h-24 text-center">
                  {__('No workflows here yet.', 'yay-automation')}
                </TableCell>
              </TableRow>
            )}
            {rows.map((workflow) => (
              <TableRow
                key={workflow.id}
                className="cursor-pointer"
                // The whole row opens the Overview. The name link keeps keyboard and middle-click access;
                // the link, the whole checkbox cell and the Delete button handle their own clicks.
                onClick={(event) => {
                  if (!(event.target as HTMLElement).closest('a, button, [data-row-select]')) {
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
                      __('Select %s', 'yay-automation'),
                      workflow.name,
                    )}
                  />
                </TableCell>
                <TableCell>
                  <Link to={`/workflows/${workflow.id}`} className="hover:text-primary font-medium">
                    {workflow.name}
                  </Link>
                </TableCell>
                <TableCell>
                  <div className="flex items-center gap-1.5">
                    <WorkflowStatusBadge state={onOffState(workflow)} />
                    {workflow.draft && (
                      <Badge variant="warning-soft">{__('Unapplied changes', 'yay-automation')}</Badge>
                    )}
                  </div>
                </TableCell>
                <TableCell className="text-muted-foreground">{triggerSummary(workflow)}</TableCell>
                <TableCell className="text-muted-foreground">{formatDateTime(workflow.lastVersionAt)}</TableCell>
                <TableCell>
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={sprintf(
                      /* translators: %s: workflow name */
                      __('Delete %s', 'yay-automation'),
                      workflow.name,
                    )}
                    onClick={() => setPendingDelete(workflow)}
                  >
                    <Trash2 />
                  </Button>
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
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

      <DeleteWorkflowDialog workflow={pendingDelete} onClose={() => setPendingDelete(null)} />
    </>
  );
}

export function WorkflowsTableSkeleton() {
  return (
    <div className="overflow-x-auto rounded-lg border">
      <Table>
        <TableHeader>
          <TableHeadRow select={null} />
        </TableHeader>
        <TableBody>
          {Array.from({ length: 3 }, (_, i) => (
            <TableRow key={i}>
              {Array.from({ length: 6 }, (_, j) => (
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
