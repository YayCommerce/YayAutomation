import { useState } from 'react';
import { Plus } from 'lucide-react';
import { useNavigate } from 'react-router';
import { __ } from '@wordpress/i18n';

import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/sonner';
import { useCreateWorkflowMutation } from '../../api/workflow.query';
import { LIBRARY_PATH } from '../nav/library.nav';
import { CreateWorkflowDialog } from './CreateWorkflowDialog';

/** The app header's Create button and its dialog. */
export function CreateWorkflowButton({
  beforeCreate,
}: {
  /** Runs before the workflow is created; false creates nothing (the editor's failed autosave). */
  beforeCreate?: () => Promise<boolean>;
}) {
  const [open, setOpen] = useState(false);
  const navigate = useNavigate();
  const createMutation = useCreateWorkflowMutation({
    onSaved: (workflow) => {
      setOpen(false);
      navigate(`/workflows/${workflow.id}/edit`);
    },
    onFailed: (error) => toast.error(error.message),
  });

  const [preparing, setPreparing] = useState(false);
  const create = async () => {
    if (beforeCreate) {
      setPreparing(true);
      const ready = await beforeCreate();
      setPreparing(false);
      if (!ready) {
        toast.error(__('Your latest changes couldn’t be saved, so no workflow was created.', 'yaymatik'));
        return;
      }
    }
    createMutation.mutate({});
  };

  return (
    <>
      <Button variant="outline" onClick={() => setOpen(true)}>
        <Plus />
        <span className="hidden sm:inline">{__('Create workflow', 'yaymatik')}</span>
        <span className="sm:hidden">{__('Create', 'yaymatik')}</span>
      </Button>
      <CreateWorkflowDialog
        open={open}
        onOpenChange={setOpen}
        creating={preparing || createMutation.isPending}
        onSelectTrigger={() => void create()}
        onBrowseTemplates={() => {
          setOpen(false);
          navigate(LIBRARY_PATH);
        }}
      />
    </>
  );
}
