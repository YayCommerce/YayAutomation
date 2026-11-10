import { expect, test, type Page } from '@playwright/test';

import { FIRST_ORDER, useTemplate } from '../support/journey';
import { resetSite } from '../support/wp';

test.beforeEach(() => resetSite());

const workflowId = (page: Page) => Number(/#\/workflows\/(\d+)/.exec(page.url())?.[1]);

/** Header Create → Choose a trigger; ends in the editor of the new workflow. */
async function createFromHeader(page: Page, from: number) {
  await page.locator('#yay-header-actions').getByRole('button', { name: /Create/ }).click();
  await page.getByRole('dialog').getByRole('button', { name: /Choose a trigger/ }).click();
  await page.waitForURL((url) => /#\/workflows\/\d+\/edit$/.test(url.hash) && !url.hash.includes(`/${from}/`));
  // The dialog's title is also "New workflow": the editor's header only counts once it has closed.
  await expect(page.getByRole('dialog', { name: 'New workflow' })).toHaveCount(0);
  await expect(page.getByText('New workflow').first()).toBeVisible();
}

test('creates a workflow from the header on the editor and the overview', async ({ page }) => {
  await useTemplate(page, FIRST_ORDER);
  const template = workflowId(page);

  await createFromHeader(page, template);
  expect(workflowId(page)).toBeGreaterThan(template);

  await page.goto(`/wp-admin/admin.php?page=yaymatik#/workflows/${template}`);
  await createFromHeader(page, template);
  expect(workflowId(page)).toBeGreaterThan(template);
});

test('the list has no header Create button', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=yaymatik#/workflows');
  await expect(page.getByText('Automate your store’s routine work')).toBeVisible();
  await expect(page.locator('#yay-header-actions').getByRole('button')).toHaveCount(0);
});
