import { useRef } from 'react';
import type { WorkflowGraph } from '@yaymatik/types';
import { useNavigate } from 'react-router';
import { __, sprintf } from '@wordpress/i18n';

import { toast } from '@/components/ui/sonner';
import { useCreateWorkflowMutation } from '../../api/workflow.query';
import { sendsCustomerEmail } from '../../templates';

/** "Install WooCommerce before activating." for plugins a template uses that aren't installed; null when none. */
export const installFirst = (pluginNames: string[]) =>
  pluginNames.length
    ? sprintf(
        /* translators: %s: plugin name(s), e.g. "WooCommerce" */
        __('Install %s before activating.', 'yaymatik'),
        pluginNames.join(', '),
      )
    : null;

/**
 * Creates an inactive draft right away and opens it in the editor. Plugins the template needs but
 * aren't installed don't block it; the toast says to install them first.
 */
export function useCreateDraft() {
  const navigate = useNavigate();
  // Hook-level callbacks, not `mutate(…, { onSuccess })`: those are skipped if the page unmounts
  // mid-request (e.g. Back on the preview), which would create a draft with no feedback.
  // Set on click, read once saved: refs, so the toast doesn't depend on a render in between.
  const missing = useRef<string[]>([]);
  const emails = useRef(false);
  const createMutation = useCreateWorkflowMutation({
    onSaved: (workflow) => {
      const message = emails.current
        ? __('Draft created. Review the emails, then activate it.', 'yaymatik')
        : __('Draft created. Review it, then activate it.', 'yaymatik');
      toast.success(message, {
        description: installFirst(missing.current) ?? undefined,
      });
      navigate(`/workflows/${workflow.id}/edit`);
    },
    onFailed: (error) => toast.error(error.message),
  });

  /** `missingPlugins`: names of the plugins it uses that aren't installed. */
  const createDraft = (name: string, graph: WorkflowGraph, missingPlugins: string[] = []) => {
    missing.current = missingPlugins;
    emails.current = sendsCustomerEmail(graph);
    // Cloned so edits never touch a shared template object.
    createMutation.mutate({ name, graph: structuredClone(graph) });
  };

  return { createDraft, isCreating: createMutation.isPending };
}
