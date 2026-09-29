import { useState } from 'react';
import type { Workflow } from '@yay-automation/types';
import { Trash2 } from 'lucide-react';
import { Link } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { formatDateTime } from '@/lib/helpers/date.helper';
import {
  AlertDialog,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button, LoadingButton } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { toast } from '@/components/ui/sonner';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { blocks } from './blocks';
import { useDeleteWorkflowMutation } from './editor/workflow.query';

function triggerSummary(workflow: Workflow) {
  const trigger = workflow.graph.nodes.find((node) => node.kind === 'trigger');
  if (!trigger) return '—';
  const block = blocks[trigger.data.block];
  return block.summary(trigger.data.config) ?? block.label;
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
  const deleteMutation = useDeleteWorkflowMutation();

  const confirmDelete = () => {
    if (!pendingDelete) return;
    deleteMutation.mutate(pendingDelete.id, {
      onSuccess: () => {
        toast.success(__('Workflow deleted', 'yay-automation'));
        setPendingDelete(null);
      },
      onError: (error) => toast.error(error.message),
    });
  };

  return (
    <>
      <Table>
        <TableHeader>
          <TableHeadRow />
        </TableHeader>
        <TableBody>
          {workflows.map((workflow) => (
            <TableRow key={workflow.id}>
              <TableCell>
                <Link to={`/workflows/${workflow.id}`} className="hover:text-primary font-medium">
                  {workflow.name}
                </Link>
              </TableCell>
              <TableCell>
                {workflow.status === 'active' ? (
                  <Badge variant="success-soft">{__('Active', 'yay-automation')}</Badge>
                ) : (
                  <Badge variant="secondary">{__('Inactive', 'yay-automation')}</Badge>
                )}
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

      <AlertDialog
        open={!!pendingDelete}
        // Stays open while deleting, so a failure can be retried.
        onOpenChange={(open) => !open && !deleteMutation.isPending && setPendingDelete(null)}
      >
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>
              {sprintf(
                /* translators: %s: workflow name */
                __('Delete “%s”?', 'yay-automation'),
                pendingDelete?.name ?? '',
              )}
            </AlertDialogTitle>
            <AlertDialogDescription>
              {__('The workflow stops running right away. This can’t be undone.', 'yay-automation')}
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel disabled={deleteMutation.isPending}>{__('Cancel', 'yay-automation')}</AlertDialogCancel>
            {/* Not AlertDialogAction: that closes the dialog before the request finishes. */}
            <LoadingButton
              variant="destructive"
              loading={deleteMutation.isPending}
              disabled={deleteMutation.isPending}
              onClick={confirmDelete}
            >
              {__('Delete', 'yay-automation')}
            </LoadingButton>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
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
