import { useId, useState, type FormEvent } from 'react';
import { __ } from '@wordpress/i18n';

import { Button, LoadingButton } from '@/components/ui/button';
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/** A dialog that edits one value: mount it to open it; it can't close while saving. */
export function FieldDialog({
  title,
  description,
  label,
  initialValue,
  type = 'text',
  pending,
  error,
  onSave,
  onClose,
}: {
  title: string;
  /** For screen readers only. */
  description: string;
  label: string;
  initialValue: string;
  type?: 'text' | 'email';
  pending: boolean;
  /** Shown under the input. */
  error?: string;
  onSave: (value: string) => void;
  onClose: () => void;
}) {
  const [value, setValue] = useState(initialValue);
  const id = useId();

  const submit = (event: FormEvent) => {
    event.preventDefault();
    onSave(value.trim());
  };

  return (
    <Dialog open onOpenChange={(open) => !open && !pending && onClose()}>
      <DialogContent className="sm:max-w-md">
        {/* noValidate: the server's message shows under the input, not the browser's bubble. */}
        <form onSubmit={submit} noValidate className="grid gap-4">
          <DialogHeader>
            <DialogTitle>{title}</DialogTitle>
            <DialogDescription className="sr-only">{description}</DialogDescription>
          </DialogHeader>
          <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
              id={id}
              type={type}
              value={value}
              onChange={(event) => setValue(event.target.value)}
              aria-invalid={!!error}
              aria-describedby={error ? `${id}-error` : undefined}
              autoFocus
            />
            {error && (
              <p id={`${id}-error`} className="text-destructive text-[13px]">
                {error}
              </p>
            )}
          </div>
          <DialogFooter>
            <DialogClose asChild>
              <Button variant="outline" disabled={pending}>
                {__('Cancel', 'yaymatik-automation-for-woocommerce')}
              </Button>
            </DialogClose>
            <LoadingButton type="submit" loading={pending} disabled={!value.trim() || pending}>
              {__('Save', 'yaymatik-automation-for-woocommerce')}
            </LoadingButton>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
