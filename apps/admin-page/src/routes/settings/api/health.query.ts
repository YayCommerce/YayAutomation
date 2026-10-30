import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import type { FixableHealthCheckId, HealthChecksResponse } from '@yaymatik/types';

import { fixHealthCheck, getHealthChecks } from './health.api';

const healthKey = ['health'] as const;

/** One scan per visit to the page; later ones only through `refetch` ("Check again"), never on focus or reconnect. */
export function useHealthQuery() {
  return useQuery({
    queryKey: healthKey,
    queryFn: getHealthChecks,
    staleTime: Infinity,
    refetchOnMount: 'always',
    refetchOnWindowFocus: false,
    retry: 1,
  });
}

/** One per check, so each row has its own pending state. */
export function useFixHealthCheck(check: FixableHealthCheckId) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: () => fixHealthCheck(check),
    // A scan still running may have read the runs before this fix, so it's cancelled. The fix
    // response has no `checkedAt`: its checks ran just now.
    onSuccess: async ({ checks }) => {
      await queryClient.cancelQueries({ queryKey: healthKey });
      queryClient.setQueryData<HealthChecksResponse>(healthKey, { checks, checkedAt: new Date().toISOString() });
    },
  });
}
