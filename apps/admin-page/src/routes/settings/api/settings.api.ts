import type { Settings, SettingsFieldError, SettingsResponse } from '@yaymatik/types';
import type { ResponsePromise } from 'ky';

import { api } from '@/lib/api/api';
import { request } from './request';

/** A failed request, with the server's per-field errors when it refused values (400). */
export class SettingsError extends Error {
  constructor(
    message: string,
    readonly fieldErrors: SettingsFieldError[] = [],
  ) {
    super(message);
  }
}

const settingsRequest = (response: ResponsePromise) =>
  request<SettingsResponse>(response, (message, json) => {
    const errors = (json as { data?: { errors?: unknown } } | null)?.data?.errors;
    return new SettingsError(message, Array.isArray(errors) ? errors : []);
  });

export const getSettings = () => settingsRequest(api.get('settings'));

/** Partial: keys left out keep their value. */
export const saveSettings = (changes: Partial<Settings>) => settingsRequest(api.put('settings', { json: changes }));
