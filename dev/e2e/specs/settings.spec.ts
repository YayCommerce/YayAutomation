import { expect, test } from '@playwright/test';

import { removeCleanupJob, resetSite } from '../support/wp';

test.beforeEach(() => resetSite());

const SETTINGS = '/wp-admin/admin.php?page=yaymatik#/settings';
const CLEANUP_LATE = 'The daily cleanup isn’t running on time';

test('saves a setting', async ({ page }) => {
  await page.goto(SETTINGS);
  await page.getByLabel('Keep run history for').fill('120');
  await page.locator('#yay-header-actions').getByRole('button', { name: /Save/ }).click();
  await expect(page.getByText('Settings saved')).toBeVisible();
  await page.reload();
  await expect(page.getByLabel('Keep run history for')).toHaveValue('120');
});

test('the health check finds a missing daily job and schedules it again', async ({ page }) => {
  // The first load schedules the daily jobs; then one goes missing.
  await page.goto(SETTINGS);
  await expect(page.getByText('Health check')).toBeVisible();
  removeCleanupJob();
  await page.reload();
  await expect(page.getByText(CLEANUP_LATE)).toBeVisible();
  await page.getByRole('button', { name: 'Schedule again' }).click();
  await expect(page.getByText('The daily cleanup is scheduled again.')).toBeVisible();
  await expect(page.getByText(CLEANUP_LATE)).toHaveCount(0);
});
