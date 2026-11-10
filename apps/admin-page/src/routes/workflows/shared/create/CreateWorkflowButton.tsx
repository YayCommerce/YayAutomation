import { useState } from 'react';
import { Plus } from 'lucide-react';
import { useNavigate } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/sonner';
import { useCreateWorkflowMutation } from '../../api/workflow.query';
import { LIBRARY_PATH } from '../nav/library.nav';
import { CreateWorkflowDialog } from './CreateWorkflowDialog';

/**
 * The app header's Create button and its dialog. Leaving the editor goes through its leave guard, so a
 * pending autosave settles before the new workflow opens.
 */
export function CreateWorkflowButton() {
  const [open, setOpen] = useState(false);
  const navigate = useNavigate();
  const createMutation = useCreateWorkflowMutation({
    onSaved: (workflow) => {
      setOpen(false);
      navigate(`/workflows/${workflow.id}/edit`);
    },
    onFailed: (error) => toast.error(error.message),
  });

  return (
    <>
      <Button variant="outline" className="ml-2" onClick={() => setOpen(true)}>
        <Plus />
        <span className="hidden sm:inline">{__('Create workflow', 'yaymatik')}</span>
        <span className="sm:hidden">{__('Create', 'yaymatik')}</span>
      </Button>
      <CreateWorkflowDialog
        open={open}
        onOpenChange={setOpen}
        creating={createMutation.isPending}
        onSelectTrigger={() => createMutation.mutate({})}
        onBrowseTemplates={() => {
          setOpen(false);
          navigate(LIBRARY_PATH);
        }}
      />
    </>
  );
}
