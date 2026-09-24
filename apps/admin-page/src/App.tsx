import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { LazyMotion } from 'motion/react';
import { RouterProvider } from 'react-router';

import { ThemeProvider } from '@/components/ui/theme-provider';
import { router } from './router';

const queryClient = new QueryClient();

const loadMotionFeatures = () => import('motion/react').then((m) => m.domAnimation);

export default function App() {
  return (
    <ThemeProvider>
      <QueryClientProvider client={queryClient}>
        <LazyMotion features={loadMotionFeatures}>
          <RouterProvider router={router} />
        </LazyMotion>
      </QueryClientProvider>
    </ThemeProvider>
  );
}
