import * as React from 'react';

import { cn } from '@/lib/utils';

type MessageAlign = 'start' | 'end';
const MessageContext = React.createContext<{ align: MessageAlign }>({ align: 'start' });

function Message({ className, align = 'start', ...props }: React.ComponentProps<'div'> & { align?: MessageAlign }) {
  return (
    <MessageContext.Provider value={{ align }}>
      <div
        data-slot="message"
        data-align={align}
        className={cn('flex gap-2', align === 'end' && 'flex-row-reverse', className)}
        {...props}
      />
    </MessageContext.Provider>
  );
}

function MessageGroup({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="message-group" className={cn('flex flex-col gap-2', className)} {...props} />;
}

function MessageAvatar({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="message-avatar" className={cn('flex shrink-0 items-end', className)} {...props} />;
}

function MessageContent({ className, ...props }: React.ComponentProps<'div'>) {
  const { align } = React.useContext(MessageContext);
  return (
    <div
      data-slot="message-content"
      className={cn('flex max-w-[75%] flex-col gap-1', align === 'end' && 'items-end', className)}
      {...props}
    />
  );
}

function MessageHeader({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="message-header"
      className={cn('text-muted-foreground flex items-center gap-1.5 text-xs', className)}
      {...props}
    />
  );
}

function MessageFooter({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="message-footer"
      className={cn('text-muted-foreground flex items-center gap-1.5 text-xs', className)}
      {...props}
    />
  );
}

export { Message, MessageGroup, MessageAvatar, MessageContent, MessageHeader, MessageFooter };
