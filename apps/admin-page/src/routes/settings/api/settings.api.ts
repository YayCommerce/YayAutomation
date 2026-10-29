import type { Settings, SettingsFieldError, SettingsResponse } from '@yaymatik/types';
import { HTTPError, type ResponsePromise } from 'ky';
import { __, sprintf } from '@wordpress/i18n';

import { api } from '@/lib/api/api';
import { getErrorJson } from '@/lib/helpers/response.helper';

/** A failed request, with the server's per-field errors when it refused values (400). */
export class SettingsError extends Error {
  constructor(
    message: string,
    readonly fieldErrors: SettingsFieldError[] = [],
  ) {
    super(message);
  }
}

async function request(response: ResponsePromise): Promise<SettingsResponse> {
  try {
    return await response.json<SettingsResponse>();
  } catch (error) {
    let json;
    try {
      json = await getErrorJson(error);
    } catch {
      // Error body isn't JSON (PHP fatal, proxy error page).
    }
    const fieldErrors = Array.isArray(json?.data?.errors) ? json.data.errors : [];
    let message = __('The server didn’t answer.', 'yaymatik');
    if (error instanceof HTTPError) {
      message =
        typeof json?.message === 'string'
          ? json.message
          : /* translators: %d: HTTP status code */
            sprintf(__('The server returned an error (%d).', 'yaymatik'), error.response.status);
    }
    throw new SettingsError(message, fieldErrors);
  }
}

export const getSettings = () => request(api.get('settings'));

/** Partial: keys left out keep their value. */
export const saveSettings = (changes: Partial<Settings>) => request(api.put('settings', { json: changes }));
