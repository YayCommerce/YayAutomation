import { api } from '@/lib/api/api';
import { getErrorMsg } from '@/lib/helpers/response.helper';

/** Saves where this user's test emails go; resolves to the saved address, rejects with the server's message. */
export async function saveTestEmail(email: string): Promise<string> {
  try {
    return (await api.post('test-email', { json: { email } }).json<{ testEmail: string }>()).testEmail;
  } catch (error) {
    throw new Error(await getErrorMsg(error));
  }
}
