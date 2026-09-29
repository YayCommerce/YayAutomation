import { useMemo } from 'react';
import type { WorkflowDraft } from '@yay-automation/types';
import { useSearchParams } from 'react-router';
import { __ } from '@wordpress/i18n';

import { EditorPlaceholder } from './editor/EditorLayout';
import { blankGraph } from './editor/workflow.graph';
import { WorkflowEditor } from './editor/WorkflowEditor';
import { findTemplate } from './templates/templates';

/**
 * Unsaved draft: blank, or from `?template=<id>`. Nothing exists on the server until the first Save,
 * which then moves to `/workflows/:id`.
 */
export default function NewWorkflowPage() {
  const [searchParams] = useSearchParams();
  const templateId = searchParams.get('template');

  const draft = useMemo((): WorkflowDraft | null => {
    if (!templateId) {
      return { name: __('Untitled workflow', 'yay-automation'), status: 'inactive', graph: blankGraph() };
    }
    const template = findTemplate(templateId);
    // Cloned so edits never touch the shared template object.
    return template ? { name: template.name, status: 'inactive', graph: structuredClone(template.graph) } : null;
  }, [templateId]);

  if (!draft) {
    return <EditorPlaceholder error={new Error(__('Template not found.', 'yay-automation'))} />;
  }

  return <WorkflowEditor key={templateId ?? 'blank'} workflow={draft} />;
}
