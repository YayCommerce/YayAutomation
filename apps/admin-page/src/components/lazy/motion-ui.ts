import { lazy } from 'react';

const load = () => import('@/components/motion-ui');

export const CopyButton = lazy(() => load().then((m) => ({ default: m.CopyButton })));
