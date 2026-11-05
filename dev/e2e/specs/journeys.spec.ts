import { expect, test } from '@playwright/test';

import {
  activate,
  addToCart,
  CHECKOUT_REMINDER,
  CHECKOUT_SUBJECT,
  enterEmail,
  FIRST_ORDER,
  FIRST_ORDER_SUBJECT,
  guestPage,
  mailRow,
  openMailLog,
  placeOrder,
  uniqueEmail,
  useTemplate,
} from '../support/journey';
import { abandonCart, completeOrder, endWaits, resetSite, runQueue } from '../support/wp';

test.beforeEach(() => resetSite());

test('builds a workflow from a template', async ({ page }) => {
  await page.goto('/wp-admin/admin.php?page=yaymatik#/workflows');
  await page.getByText('Browse templates').first().click();
  await page.getByPlaceholder('Search templates').fill('First-order');
  await page.getByRole('link', { name: /First-order thank-you: % off/ }).click();
  await page.getByRole('button', { name: 'Use template' }).first().click();
  await page.waitForURL(/#\/workflows\/\d+\/edit/);
  await expect(page.getByRole('button', { name: 'Activate', exact: true })).toBeEnabled();
  await expect(page.getByText('Needs setup')).toHaveCount(0);
});

test('activates a workflow', async ({ page }) => {
  await useTemplate(page, FIRST_ORDER);
  await activate(page);
  await page.goto('/wp-admin/admin.php?page=yaymatik#/workflows');
  await expect(page.getByRole('row', { name: /First-order thank-you/ }).getByText('Active')).toBeVisible();
});

test('an order runs the workflow and logs its email', async ({ page, browser, baseURL }) => {
  await useTemplate(page, FIRST_ORDER);
  await activate(page);
  const overview = page.url();

  const email = uniqueEmail();
  const shopper = await guestPage(browser, baseURL);
  await addToCart(shopper);
  await placeOrder(shopper, email);
  completeOrder(email);
  runQueue();

  await page.goto(overview);
  await page.reload();
  await expect(page.getByRole('row').filter({ hasText: 'Completed' })).toHaveCount(1);

  await openMailLog(page);
  await expect(mailRow(page, email, FIRST_ORDER_SUBJECT)).toBeVisible();
});

test('a deactivated workflow does not run', async ({ page, browser, baseURL }) => {
  await useTemplate(page, FIRST_ORDER);
  await activate(page);
  const overview = page.url();
  await page.getByRole('button', { name: 'Deactivate' }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Deactivate' }).click();
  await expect(page.getByText('Inactive').first()).toBeVisible();

  const email = uniqueEmail();
  const shopper = await guestPage(browser, baseURL);
  await addToCart(shopper);
  await placeOrder(shopper, email);
  completeOrder(email);
  runQueue();

  await page.goto(overview);
  await page.reload();
  await expect(page.getByText('Each time this workflow starts')).toBeVisible();
  await openMailLog(page);
  await expect(mailRow(page, email, FIRST_ORDER_SUBJECT)).toHaveCount(0);
});

test('an abandoned checkout gets a reminder that restores the cart', async ({ page, browser, baseURL }) => {
  await useTemplate(page, CHECKOUT_REMINDER);
  await activate(page);

  const email = uniqueEmail();
  const shopper = await guestPage(browser, baseURL);
  await addToCart(shopper);
  const captured = shopper.waitForResponse(
    (response) =>
      response.url().includes('/wc/store/') && response.ok() && !!response.request().postData()?.includes(email),
  );
  await enterEmail(shopper, email);
  await captured;
  await shopper.context().close();

  abandonCart(email);
  runQueue();
  await openMailLog(page);
  await expect(mailRow(page, email, CHECKOUT_SUBJECT)).toHaveCount(0);

  endWaits();
  runQueue();
  await openMailLog(page);
  await mailRow(page, email, CHECKOUT_SUBJECT).getByRole('link').click();
  const link = page.frameLocator('iframe[title="Mail body"]').locator('a[href*="yaymatik_cart"]').first();
  const restoreUrl = await link.getAttribute('href');

  const returning = await guestPage(browser, baseURL);
  await returning.goto(restoreUrl as string);
  await returning.goto('/cart/');
  // Inside the items table: an empty cart also lists Seed Tee, under "New in store".
  await expect(returning.locator('.wc-block-cart-items').getByText('Seed Tee').first()).toBeVisible();
});
