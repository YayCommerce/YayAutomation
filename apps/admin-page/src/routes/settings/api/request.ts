import { HTTPError, type ResponsePromise } from 'ky';
import { __, sprintf } from '@wordpress/i18n';

import { getErrorJson } from '@/lib/helpers/response.helper';

/** The response's JSON; a failure throws `toError( message to show, error body )`. */
export async function request<T>(
  response: ResponsePromise,
  toError: (message: string, json: unknown) => Error = (message) => new Error(message),
): Promise<T> {
  try {
    return await response.json<T>();
  } catch (error) {
    let json;
    try {
      json = await getErrorJson(error);
    } catch {
      // Error body isn't JSON (PHP fatal, proxy error page).
    }
    let message = __('The server didn’t answer.', 'yaymatik-automation-for-woocommerce');
    if (error instanceof HTTPError) {
      message =
        typeof json?.message === 'string'
          ? json.message
          : /* translators: %d: HTTP status code */
            sprintf(
              __('The server returned an error (%d).', 'yaymatik-automation-for-woocommerce'),
              error.response.status,
            );
    }
    throw toError(message, json);
  }
}
