import type { FixableHealthCheckId, HealthChecksResponse, HealthFixResponse } from '@yaymatik/types';

import { api } from '@/lib/api/api';
import { request } from './request';

/** Reads up to 500 runs on the server: on demand only, never polled, and no ky retries on top of the query's one. */
export const getHealthChecks = () => request<HealthChecksResponse>(api.get('health/checks', { retry: 0 }));

/** The server runs the check again and fixes what it finds; ids never come from here. */
export const fixHealthCheck = (check: FixableHealthCheckId) =>
  request<HealthFixResponse>(api.post('health/fix', { json: { check } }));
