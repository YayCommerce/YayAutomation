import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

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
    onSuccess: (saved) => queryClient.setQueryData(settingsKey, saved),
  });
}
