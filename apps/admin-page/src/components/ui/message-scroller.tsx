import * as React from 'react';
import { ArrowDownIcon } from 'lucide-react';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';

interface MessageScrollerContextValue {
  isAtBottom: boolean;
  scrollToBottom: () => void;
}

const MessageScrollerContext = React.createContext<MessageScrollerContextValue>({
  isAtBottom: true,
  scrollToBottom: () => {},
});

export function useMessageScroller() {
  return React.useContext(MessageScrollerContext);
}

function MessageScrollerProvider({ children }: { children: React.ReactNode }) {
  const viewportRef = React.useRef<HTMLDivElement>(null);
  const [isAtBottom, setIsAtBottom] = React.useState(true);

  const scrollToBottom = React.useCallback(() => {
    if (viewportRef.current) {
      viewportRef.current.scrollTop = viewportRef.current.scrollHeight;
    }
  }, []);

  // Auto-scroll when content grows while pinned to bottom
  const resizeObserverRef = React.useRef<ResizeObserver | null>(null);
  React.useEffect(() => {
    const viewport = viewportRef.current;
    if (!viewport) return;

    resizeObserverRef.current = new ResizeObserver(() => {
      if (isAtBottom) scrollToBottom();
    });
    resizeObserverRef.current.observe(viewport);
    return () => resizeObserverRef.current?.disconnect();
  }, [isAtBottom, scrollToBottom]);

  const handleScroll = React.useCallback(() => {
    const el = viewportRef.current;
    if (!el) return;
    const atBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 16;
    setIsAtBottom(atBottom);
  }, []);

  return (
    <MessageScrollerContext.Provider value={{ isAtBottom, scrollToBottom }}>
      {React.cloneElement(children as React.ReactElement, { ref: viewportRef, onScroll: handleScroll })}
    </MessageScrollerContext.Provider>
  );
}

function MessageScroller({ className, children, ...props }: React.ComponentProps<'div'>) {
  return (
    <MessageScrollerProvider>
      <div data-slot="message-scroller" className={cn('relative flex flex-col overflow-hidden', className)} {...props}>
        {children}
      </div>
    </MessageScrollerProvider>
  );
}

const MessageScrollerViewport = React.forwardRef<HTMLDivElement, React.ComponentProps<'div'>>(
  ({ className, ...props }, ref) => (
    <div
      ref={ref}
      data-slot="message-scroller-viewport"
      className={cn('flex-1 overflow-y-auto', className)}
      {...props}
    />
  ),
);

function MessageScrollerContent({ className, ...props }: React.ComponentProps<'div'>) {
  return (
    <div
      data-slot="message-scroller-content"
      aria-live="polite"
      aria-relevant="additions"
      className={cn('flex flex-col gap-4 p-4', className)}
      {...props}
    />
  );
}

function MessageScrollerItem({
  className,
  messageId: _messageId,
  ...props
}: React.ComponentProps<'div'> & { messageId?: string }) {
  return <div data-slot="message-scroller-item" className={cn('', className)} {...props} />;
}

function MessageScrollerButton({ className, ...props }: React.ComponentProps<typeof Button>) {
  const { isAtBottom, scrollToBottom } = useMessageScroller();

  if (isAtBottom) return null;

  return (
    <Button
      data-slot="message-scroller-button"
      variant="outline"
      size="icon"
      onClick={scrollToBottom}
      className={cn('absolute bottom-4 left-1/2 -translate-x-1/2 rounded-full shadow-md', className)}
      {...props}
    >
      <ArrowDownIcon />
      <span className="sr-only">Scroll to latest</span>
    </Button>
  );
}

export { MessageScroller, MessageScrollerViewport, MessageScrollerContent, MessageScrollerItem, MessageScrollerButton };
