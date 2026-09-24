import { styled } from '@/lib/styled';
import { cn } from '@/lib/utils';

export const PageCard = styled.div('bg-background mx-4 my-6 max-w-7xl grow rounded-lg p-6 2xl:mx-auto');

export const PageTitle = styled.h1('flex items-center gap-2 text-lg font-semibold');

export function PageLink({
  className,
  target = '_blank',
  rel = 'noopener noreferrer',
  ...props
}: React.ComponentProps<'a'>) {
  return (
    <a
      target={target}
      rel={rel}
      className={cn(
        'text-primary hover:text-primary-accent text-base font-normal underline underline-offset-4',
        className,
      )}
      {...props}
    />
  );
}
