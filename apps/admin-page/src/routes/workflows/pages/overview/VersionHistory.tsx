import { useEffect, useMemo } from 'react';
import type { Workflow, WorkflowVersion, WorkflowVersionAction } from '@yaymatik/types';
import { ChevronLeft, ChevronRight, TriangleAlert } from 'lucide-react';
import { __, sprintf } from '@wordpress/i18n';

import { shortDayTime } from '@/lib/helpers/date.helper';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { useWorkflowVersionsQuery } from '../../api/workflow.query';
import { validateWorkflow } from '../../model/validation';
import { WorkflowStatusBadge } from '../../shared/ui/WorkflowStatusBadge';

const actionLabels: Record<WorkflowVersionAction, (user: string) => string> = {
  /* translators: %s: user display name */
  created: (user: string) => sprintf(__('Created by %s', 'yaymatik'), user),
  /* translators: %s: user display name */
  edited: (user: string) => sprintf(__('Edited by %s', 'yaymatik'), user),
  /* translators: %s: user display name */
  activated: (user: string) => sprintf(__('Activated by %s', 'yaymatik'), user),
  /* translators: %s: user display name */
  deactivated: (user: string) => sprintf(__('Deactivated by %s', 'yaymatik'), user),
};

/**
 * Versions of a workflow, newest first, with prev/next paging. Rows are read-only. `page`
 * (1-based) is the caller's, so it survives switching tabs.
 */
export function VersionHistory({
  workflow,
  page,
  onPageChange,
}: {
  workflow: Workflow;
  page: number;
  onPageChange: (page: number) => void;
}) {
  const { data, error, isPending, isPlaceholderData } = useWorkflowVersionsQuery(workflow.id, page);
  // Discarding a draft deletes its version, which can empty the last page: step back.
  const emptyPage = page > 1 && !isPlaceholderData && data?.items.length === 0;
  useEffect(() => {
    if (emptyPage) onPageChange(page - 1);
  }, [emptyPage, onPageChange, page]);

  return (
    <div className="flex flex-col">
      <Table>
        <TableHeader>
          <TableRow>
            <TableHead className="w-1/4">{__('Version created', 'yaymatik')}</TableHead>
            <TableHead className="w-2/5">{__('Recent history', 'yaymatik')}</TableHead>
            <TableHead>{__('Status', 'yaymatik')}</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {isPending ? (
            Array.from({ length: 3 }, (_, i) => (
              <TableRow key={i}>
                {Array.from({ length: 3 }, (_, j) => (
                  <TableCell key={j}>
                    <Skeleton className="h-4 w-32" />
                  </TableCell>
                ))}
              </TableRow>
            ))
          ) : error ? (
            <TableRow>
              <TableCell colSpan={3} className="text-muted-foreground">
                {error.message}
              </TableCell>
            </TableRow>
          ) : data.items.length === 0 ? (
            <TableRow>
              <TableCell colSpan={3} className="text-muted-foreground">
                {__('No versions yet.', 'yaymatik')}
              </TableCell>
            </TableRow>
          ) : (
            data.items.map((version) => <VersionRow key={version.id} version={version} />)
          )}
        </TableBody>
      </Table>
      <div className="flex items-center gap-1 border-t px-1 pt-3">
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={__('Previous page', 'yaymatik')}
          disabled={page === 1 || isPlaceholderData}
          onClick={() => onPageChange(page - 1)}
        >
          <ChevronLeft />
        </Button>
        <Button
          variant="ghost"
          size="icon-sm"
          aria-label={__('Next page', 'yaymatik')}
          disabled={!data?.hasMore || isPlaceholderData}
          onClick={() => onPageChange(page + 1)}
        >
          <ChevronRight />
        </Button>
      </div>
    </div>
  );
}

function VersionRow({ version }: { version: WorkflowVersion }) {
  // The name isn't versioned, so only the graph's issues count.
  const hasIssues = useMemo(
    () => validateWorkflow({ name: '', graph: version.graph }).some((issue) => issue.code !== 'name_required'),
    [version.graph],
  );

  return (
    <TableRow>
      <TableCell>
        <span className="inline-flex items-center gap-2">
          {shortDayTime(version.createdAt)}
          {hasIssues && (
            <Tooltip>
              <TooltipTrigger asChild>
                <TriangleAlert
                  className="text-warning size-4"
                  role="img"
                  aria-label={__('This version has errors', 'yaymatik')}
                />
              </TooltipTrigger>
              <TooltipContent>{__('This version has errors', 'yaymatik')}</TooltipContent>
            </Tooltip>
          )}
        </span>
      </TableCell>
      <TableCell>{actionLabels[version.action](version.user ?? __('a deleted user', 'yaymatik'))}</TableCell>
      <TableCell>{version.status && <WorkflowStatusBadge state={version.status} />}</TableCell>
    </TableRow>
  );
}
