import * as React from 'react';

import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

// ─── Context ─────────────────────────────────────────────────────────────────

interface QuestionnaireContextValue {
  items: string[];
  currentIndex: number;
  answers: Record<string, string | string[]>;
  setAnswer: (name: string, value: string | string[]) => void;
  goNext: () => void;
  goPrev: () => void;
  goSkip: () => void;
  isFirst: boolean;
  isLast: boolean;
  multiple: boolean;
}

const QuestionnaireContext = React.createContext<QuestionnaireContextValue | null>(null);

function useQuestionnaire() {
  const ctx = React.useContext(QuestionnaireContext);
  if (!ctx) throw new Error('Questionnaire components must be used within <Questionnaire>');
  return ctx;
}

// ─── Item Context ─────────────────────────────────────────────────────────────

const QuestionnaireItemContext = React.createContext<{ name: string; required: boolean }>({
  name: '',
  required: false,
});

// ─── Root ─────────────────────────────────────────────────────────────────────

interface QuestionnaireProps {
  items: string[];
  multiple?: boolean;
  onSubmit: (answers: Record<string, string | string[]>) => void;
  className?: string;
  children: React.ReactNode;
}

function Questionnaire({ items, multiple = false, onSubmit, className, children }: QuestionnaireProps) {
  const [currentIndex, setCurrentIndex] = React.useState(0);
  const [answers, setAnswers] = React.useState<Record<string, string | string[]>>({});

  const setAnswer = React.useCallback((name: string, value: string | string[]) => {
    setAnswers((prev) => ({ ...prev, [name]: value }));
  }, []);

  const goNext = React.useCallback(() => {
    if (currentIndex < items.length - 1) {
      setCurrentIndex((i) => i + 1);
    } else {
      onSubmit(answers);
    }
  }, [currentIndex, items.length, answers, onSubmit]);

  const goPrev = React.useCallback(() => {
    setCurrentIndex((i) => Math.max(0, i - 1));
  }, []);

  const goSkip = React.useCallback(() => {
    if (currentIndex < items.length - 1) {
      setCurrentIndex((i) => i + 1);
    } else {
      onSubmit(answers);
    }
  }, [currentIndex, items.length, answers, onSubmit]);

  return (
    <QuestionnaireContext.Provider
      value={{
        items,
        currentIndex,
        answers,
        setAnswer,
        goNext,
        goPrev,
        goSkip,
        isFirst: currentIndex === 0,
        isLast: currentIndex === items.length - 1,
        multiple,
      }}
    >
      <div data-slot="questionnaire" className={cn('flex flex-col gap-4', className)}>
        {children}
      </div>
    </QuestionnaireContext.Provider>
  );
}

// ─── Progress ─────────────────────────────────────────────────────────────────

function QuestionnaireProgress({ className, ...props }: React.ComponentProps<'div'>) {
  const { items, currentIndex } = useQuestionnaire();
  const percent = ((currentIndex + 1) / items.length) * 100;
  return (
    <div data-slot="questionnaire-progress" className={cn('space-y-1', className)} {...props}>
      <div className="bg-muted h-1.5 w-full overflow-hidden rounded-full">
        <div className="bg-primary h-full rounded-full transition-all duration-300" style={{ width: `${percent}%` }} />
      </div>
      <p className="text-muted-foreground text-xs">
        {currentIndex + 1} / {items.length}
      </p>
    </div>
  );
}

// ─── Item ─────────────────────────────────────────────────────────────────────

function QuestionnaireItem({
  name,
  required = false,
  className,
  children,
  ...props
}: React.ComponentProps<'div'> & { name: string; required?: boolean }) {
  const { items, currentIndex } = useQuestionnaire();
  const itemIndex = items.indexOf(name);
  if (itemIndex !== currentIndex) return null;

  return (
    <QuestionnaireItemContext.Provider value={{ name, required }}>
      <div data-slot="questionnaire-item" className={cn('space-y-4', className)} {...props}>
        {children}
      </div>
    </QuestionnaireItemContext.Provider>
  );
}

function QuestionnaireTitle({ className, ...props }: React.ComponentProps<'h3'>) {
  return <h3 data-slot="questionnaire-title" className={cn('text-base font-semibold', className)} {...props} />;
}

function QuestionnaireDescription({ className, ...props }: React.ComponentProps<'p'>) {
  return (
    <p data-slot="questionnaire-description" className={cn('text-muted-foreground text-sm', className)} {...props} />
  );
}

function QuestionnaireError({ className, ...props }: React.ComponentProps<'p'>) {
  return <p data-slot="questionnaire-error" className={cn('text-destructive text-sm', className)} {...props} />;
}

// ─── Choices ──────────────────────────────────────────────────────────────────

function QuestionnaireChoices({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="questionnaire-choices" className={cn('flex flex-col gap-2', className)} {...props} />;
}

function QuestionnaireChoice({
  value,
  className,
  children,
  ...props
}: React.ComponentProps<'button'> & { value: string }) {
  const { name } = React.useContext(QuestionnaireItemContext);
  const { answers, setAnswer, multiple } = useQuestionnaire();

  const current = answers[name];
  const isSelected = multiple ? Array.isArray(current) && current.includes(value) : current === value;

  const handleClick = () => {
    if (multiple) {
      const arr = Array.isArray(current) ? current : [];
      setAnswer(name, isSelected ? arr.filter((v) => v !== value) : [...arr, value]);
    } else {
      setAnswer(name, value);
    }
  };

  return (
    <button
      type="button"
      data-slot="questionnaire-choice"
      data-selected={isSelected}
      onClick={handleClick}
      className={cn(
        'border-border hover:bg-accent flex items-center gap-3 rounded-lg border px-4 py-3 text-left text-sm transition-colors',
        'data-[selected=true]:border-primary data-[selected=true]:bg-primary/5',
        className,
      )}
      {...props}
    >
      <span
        className={cn(
          'border-muted-foreground flex size-4 shrink-0 items-center justify-center rounded-full border-2',
          isSelected && 'border-primary bg-primary',
          multiple && 'rounded',
        )}
      >
        {isSelected && <span className={cn('bg-primary-foreground size-1.5 rounded-full', multiple && 'rounded-sm')} />}
      </span>
      {children}
    </button>
  );
}

// ─── Input ────────────────────────────────────────────────────────────────────

function QuestionnaireInput({
  className,
  placeholder = 'Type your answer…',
  ...props
}: React.ComponentProps<typeof Textarea>) {
  const { name } = React.useContext(QuestionnaireItemContext);
  const { answers, setAnswer } = useQuestionnaire();
  const value = typeof answers[name] === 'string' ? (answers[name] as string) : '';

  return (
    <Textarea
      data-slot="questionnaire-input"
      value={value}
      onChange={(e) => setAnswer(name, e.target.value)}
      placeholder={placeholder}
      className={cn('min-h-20 resize-none', className)}
      {...props}
    />
  );
}

// ─── Actions ──────────────────────────────────────────────────────────────────

function QuestionnaireActions({ className, ...props }: React.ComponentProps<'div'>) {
  return <div data-slot="questionnaire-actions" className={cn('flex items-center gap-2', className)} {...props} />;
}

function QuestionnairePrevious({ children, className, ...props }: React.ComponentProps<typeof Button>) {
  const { goPrev, isFirst } = useQuestionnaire();
  return (
    <Button type="button" variant="outline" onClick={goPrev} disabled={isFirst} className={className} {...props}>
      {children ?? 'Back'}
    </Button>
  );
}

function QuestionnaireSkip({ children, className, ...props }: React.ComponentProps<typeof Button>) {
  const { goSkip } = useQuestionnaire();
  return (
    <Button type="button" variant="ghost" onClick={goSkip} className={cn('ml-auto', className)} {...props}>
      {children ?? 'Skip'}
    </Button>
  );
}

function QuestionnaireNext({ children, className, ...props }: React.ComponentProps<typeof Button>) {
  const { goNext, isLast } = useQuestionnaire();
  if (isLast) return null;
  return (
    <Button type="button" variant="primary" onClick={goNext} className={className} {...props}>
      {children ?? 'Next'}
    </Button>
  );
}

function QuestionnaireSubmit({ children, className, ...props }: React.ComponentProps<typeof Button>) {
  const { goNext, isLast } = useQuestionnaire();
  if (!isLast) return null;
  return (
    <Button type="button" variant="primary" onClick={goNext} className={className} {...props}>
      {children ?? 'Submit'}
    </Button>
  );
}

export {
  Questionnaire,
  QuestionnaireProgress,
  QuestionnaireItem,
  QuestionnaireTitle,
  QuestionnaireDescription,
  QuestionnaireChoices,
  QuestionnaireChoice,
  QuestionnaireInput,
  QuestionnaireError,
  QuestionnaireActions,
  QuestionnairePrevious,
  QuestionnaireSkip,
  QuestionnaireNext,
  QuestionnaireSubmit,
};
