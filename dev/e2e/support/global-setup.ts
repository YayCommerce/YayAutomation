import { chromium, type FullConfig } from '@playwright/test';

import { resetSite } from './wp';

export default async function globalSetup(config: FullConfig) {
  resetSite();
  const { baseURL, storageState } = config.projects[0].use;
  const browser = await chromium.launch();
  const page = await browser.newPage({ baseURL });
  await page.goto('/wp-login.php');
  await page.getByLabel('Username or Email Address').fill('admin');
  await page.getByLabel('Password', { exact: true }).fill('password');
  await page.getByRole('button', { name: 'Log In' }).click();
  await page.waitForURL('**/wp-admin/**');
  await page.context().storageState({ path: storageState as string });
  await browser.close();
}
