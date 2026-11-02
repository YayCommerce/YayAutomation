import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { workflowKeys } from '@/routes/workflows/api/workflow.query';
import { getSettings, saveSettings } from './settings.api';

const settingsKey = ['settings'] as const;

/** Loaded once; saves update it. Changes made in another tab show after a reload. */
export function useSettingsQuery() {
  return useQuery({
    queryKey: settingsKey,
    queryFn: getSettings,
    staleTime: Infinity,
    refetchOnWindowFocus: false,
    // One retry, so a failed load shows its error in about a second instead of after three backoffs.
    retry: 1,
  });
}

export function useSaveSettings() {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: saveSettings,
    onSuccess: (saved) => {
      queryClient.setQueryData(settingsKey, saved);
      const { wc } = window.yayMatikMeta;
      // A cached workflow holds issues about the old value, and the editor keeps what it first gets.
      if (saved.cartTracking !== wc.cartTracking) queryClient.removeQueries({ queryKey: workflowKeys.all });
      // Set once per page load, and the workflow pages read it without a reload.
      wc.cartTracking = saved.cartTracking;
      wc.abandonMinutes = saved.abandonMinutes;
    },
  });
}
