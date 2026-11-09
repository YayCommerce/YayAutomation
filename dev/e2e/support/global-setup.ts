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
  // The web container mounts mu-plugins apart from the cli one, so check it too.
  const mailLog = await page.goto('/wp-admin/tools.php?page=yay-mail-log');
  if (!mailLog?.ok()) {
    throw new Error('Mail log mu-plugin is not loaded on the site. Run: pnpm wp-env stop && pnpm wp-env start');
  }
  await page.context().storageState({ path: storageState as string });
  await browser.close();
}
