import { useState } from 'react';
import type { Workflow } from '@yay-automation/types';
import { Trash2 } from 'lucide-react';
import { Link, useNavigate } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { formatDateTime } from '@/lib/helpers/date.helper';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { blocks } from './blocks';
import { DeleteWorkflowDialog } from './editor/WorkflowActions';
import { workflowState, WorkflowStatusBadge } from './editor/WorkflowStatusBadge';

function triggerSummary(workflow: Workflow) {
  const trigger = workflow.graph.nodes.find((node) => node.kind === 'trigger');
  if (!trigger) return '—';
  return blocks[trigger.data.block].summary(trigger.data.config);
}

function TableHeadRow() {
  return (
    <TableRow>
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

export function WorkflowsTable({ workflows }: { workflows: Workflow[] }) {
  const [pendingDelete, setPendingDelete] = useState<Workflow | null>(null);
  const navigate = useNavigate();

  return (
    <>
      <Table>
        <TableHeader>
          <TableHeadRow />
        </TableHeader>
        <TableBody>
          {workflows.map((workflow) => (
            <TableRow
              key={workflow.id}
              className="cursor-pointer"
              // The whole row opens the Overview. The name link keeps keyboard and middle-click access;
              // the link and Delete button handle their own clicks.
              onClick={(event) => {
                if (!(event.target as HTMLElement).closest('a, button')) navigate(`/workflows/${workflow.id}`);
              }}
            >
              <TableCell>
                <Link to={`/workflows/${workflow.id}`} className="hover:text-primary font-medium">
                  {workflow.name}
                </Link>
              </TableCell>
              <TableCell>
                <div className="flex items-center gap-1.5">
                  <WorkflowStatusBadge state={workflowState(workflow)} />
                  {workflow.draft && <Badge variant="warning-soft">{__('Unapplied changes', 'yay-automation')}</Badge>}
                </div>
              </TableCell>
              <TableCell className="text-muted-foreground">{triggerSummary(workflow)}</TableCell>
              <TableCell className="text-muted-foreground">{formatDateTime(workflow.updatedAt)}</TableCell>
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

      <DeleteWorkflowDialog workflow={pendingDelete} onClose={() => setPendingDelete(null)} />
    </>
  );
}

export function WorkflowsTableSkeleton() {
  return (
    <Table>
      <TableHeader>
        <TableHeadRow />
      </TableHeader>
      <TableBody>
        {Array.from({ length: 3 }, (_, i) => (
          <TableRow key={i}>
            {Array.from({ length: 5 }, (_, j) => (
              <TableCell key={j}>
                <Skeleton className="h-5" />
              </TableCell>
            ))}
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
