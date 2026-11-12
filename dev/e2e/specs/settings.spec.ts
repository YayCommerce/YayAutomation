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
  // Through Docker the reset's CLI work can land after the removal and schedule the job again: retry both.
  await expect(async () => {
    removeCleanupJob();
    await page.goto(SETTINGS);
    // A second goto to the same `#/settings` URL is a same-document navigation: reload to check again.
    await page.reload();
    await expect(page.getByText(CLEANUP_LATE)).toBeVisible({ timeout: 5_000 });
  }).toPass({ timeout: 30_000 });
  await page.getByRole('button', { name: 'Schedule again' }).click();
  await expect(page.getByText('The daily cleanup is scheduled again.')).toBeVisible();
  await expect(page.getByText(CLEANUP_LATE)).toHaveCount(0);
});
