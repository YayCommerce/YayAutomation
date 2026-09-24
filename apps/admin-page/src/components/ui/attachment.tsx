import * as React from 'react';
import { Slot } from '@radix-ui/react-slot';
import { cva, type VariantProps } from 'class-variance-authority';
import { FileIcon, XIcon } from 'lucide-react';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { focusVariants } from '@/components/ui/variants/focus.variants';
import { inputVariants } from '@/components/ui/variants/input.variants';

const attachmentVariants = cva('group/attachment relative flex gap-3 rounded-lg border', {
  variants: {
    size: {
      default: 'p-3',
      sm: 'p-2',
      xs: 'p-1.5',
    },
    orientation: {
      horizontal: 'flex-row items-center',
      vertical: 'flex-col',
    },
    state: {
      idle: '',
      uploading: 'opacity-60',
      processing: 'opacity-60',
      error: 'border-destructive',
      done: '',
    },
  },
  defaultVariants: {
    size: 'default',
    orientation: 'horizontal',
    state: 'done',
  },
});

function Attachment({
  className,
  size = 'default',
  orientation = 'horizontal',
  state = 'done',
  ...props
}: React.ComponentProps<'div'> & VariantProps<typeof attachmentVariants>) {
  return (
    <div
      data-slot="attachment"
      data-state={state}
      data-size={size}
      data-orientation={orientation}
      className={cn(
        attachmentVariants({ size, orientation, state }),
        focusVariants(),
        inputVariants({ variant: 'input' }),
        className,
      )}
      {...props}
    />
  );
}

function AttachmentTrigger({
  className,
  asChild = false,
  ...props
}: React.ComponentProps<'button'> & { asChild?: boolean }) {
  const Comp = asChild ? Slot : 'button';
  return (
    <Comp
      data-slot="attachment-trigger"
      className={cn(
        'focus-visible:ring-ring absolute inset-0 rounded-[inherit] focus:outline-none focus-visible:ring-2',
        className,
      )}
      {...props}
    />
  );
}

const attachmentMediaVariants = cva(
  'flex size-10 shrink-0 items-center justify-center overflow-hidden rounded-md group-data-[size=sm]/attachment:size-8 group-data-[size=xs]/attachment:size-6 group-data-[size=xs]/attachment:rounded-xs',
  {
    variants: {
      variant: {
        icon: 'bg-muted text-muted-foreground',
        image: 'bg-muted',
      },
    },
    defaultVariants: { variant: 'icon' },
  },
);

function AttachmentMedia({
  className,
  variant = 'icon',
  children,
  ...props
}: React.ComponentProps<'div'> & VariantProps<typeof attachmentMediaVariants>) {
  return (
    <div data-slot="attachment-media" className={cn(attachmentMediaVariants({ variant }), className)} {...props}>
      {children ?? <FileIcon className="size-4" />}
    </div>
  );
}

function AttachmentContent({ className, children, ...props }: React.ComponentProps<'div'>) {
  return (
    <div data-slot="attachment-content" className={cn('min-w-0 flex-1', className)} {...props}>
      <div className="group-data-[state=processing]/attachment:hidden group-data-[state=uploading]/attachment:hidden">
        {children}
      </div>
      <div className="hidden space-y-1.5 group-data-[state=processing]/attachment:block group-data-[state=uploading]/attachment:block">
        <div className="bg-muted h-3 w-24 animate-pulse rounded" />
        <div className="bg-muted h-2 w-16 animate-pulse rounded" />
      </div>
    </div>
  );
}

function AttachmentTitle({ className, ...props }: React.ComponentProps<'p'>) {
  return (
    <p
      data-slot="attachment-title"
      className={cn('group-data-[state=error]/attachment:text-destructive truncate text-sm font-medium', className)}
      {...props}
    />
  );
}

function AttachmentDescription({ className, ...props }: React.ComponentProps<'p'>) {
  return (
    <p
      data-slot="attachment-description"
      className={cn('text-muted-foreground truncate text-xs', className)}
      {...props}
    />
  );
}

function AttachmentActions({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="attachment-actions"
      className={cn('relative z-10 flex shrink-0 items-center gap-1', className)}
      {...props}
    />
  );
}

function AttachmentAction({ className, children, ...props }: React.ComponentProps<typeof Button>) {
  return (
    <Button data-slot="attachment-action" variant="ghost" size="icon-sm" className={cn('size-6', className)} {...props}>
      {children ?? <XIcon />}
    </Button>
  );
}

function AttachmentGroup({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="attachment-group" className={cn('flex gap-2 overflow-x-auto', className)} {...props} />;
}

type AttachmentDropzoneError = 'invalid_type' | 'file_too_large';

interface AttachmentDropzoneProps extends Omit<React.ComponentProps<'input'>, 'type' | 'onChange' | 'value'> {
  value?: File | null;
  onChange?: (file: File | null) => void;
  onUploadError?: (error: AttachmentDropzoneError | null) => void;
  maxSize?: number;
  className?: string;
  accept?: string;
  disabled?: boolean;
  children?: React.ReactNode;
}

function AttachmentDropzone({
  value,
  onChange,
  onUploadError,
  className,
  maxSize = 10 * 1024 * 1024, // Max Size: 10MB
  accept,
  disabled,
  children,
  ...props
}: AttachmentDropzoneProps) {
  const inputRef = React.useRef<HTMLInputElement>(null);
  const [isDragActive, setIsDragActive] = React.useState(false);
  const [error, setError] = React.useState(false);

  const handleUploadError = (err: AttachmentDropzoneError) => {
    onUploadError?.(err);
    setError(true);
    onChange?.(null);
  };

  const handleFiles = (files: FileList | null) => {
    if (files && files[0]) {
      setError(false);
      onUploadError?.(null);

      if (accept && !accept?.includes(files[0].name.split('.')[1])) {
        handleUploadError('invalid_type');
        return;
      }

      if (files[0].size > maxSize) {
        handleUploadError('file_too_large');
        return;
      }

      onChange?.(files[0]);
    }
  };

  const onDragOver = (e: React.DragEvent) => {
    e.preventDefault();
    if (disabled) return;
    setError(false);
    setIsDragActive(true);
  };

  const onDragLeave = (e: React.DragEvent) => {
    e.preventDefault();
    if (disabled) return;
    setError(false);
    setIsDragActive(false);
  };

  const onDrop = (e: React.DragEvent) => {
    e.preventDefault();
    if (disabled) return;
    setIsDragActive(false);
    handleFiles(e.dataTransfer.files);
  };

  React.useEffect(() => {
    if (!value) {
      if (inputRef.current) {
        inputRef.current.value = '';
      }
    }
  }, [value]);

  return (
    <label
      data-slot="attachment-dropzone"
      className={cn(
        'border-muted-foreground-400 text-muted-foreground flex w-full cursor-not-allowed flex-col items-center justify-center gap-2 rounded-md border border-dashed p-4',
        !disabled && focusVariants(),
        !disabled && inputVariants({ variant: 'input' }),
        !disabled && 'hover:bg-muted cursor-pointer',
        isDragActive && 'bg-muted',
        error && 'border-destructive hover:border-destructive',
        className,
      )}
      onDragOver={onDragOver}
      onDragLeave={onDragLeave}
      onDrop={onDrop}
    >
      {children}
      <input
        ref={inputRef}
        type="file"
        className="hidden"
        onChange={(e) => handleFiles(e.target.files)}
        accept={accept}
        disabled={disabled}
        {...props}
      />
    </label>
  );
}

export {
  Attachment,
  AttachmentGroup,
  AttachmentMedia,
  AttachmentContent,
  AttachmentTitle,
  AttachmentDescription,
  AttachmentActions,
  AttachmentAction,
  AttachmentTrigger,
  AttachmentDropzone,
  type AttachmentDropzoneError,
};
