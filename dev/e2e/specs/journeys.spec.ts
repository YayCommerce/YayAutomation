import { expect, type Page, test } from '@playwright/test';

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
  placeClassicOrder,
  placeOrder,
  REVIEW_REQUEST,
  REVIEW_SUBJECT,
  uniqueEmail,
  useTemplate,
} from '../support/journey';
import { abandonCart, completeOrder, endWaits, resetSite, runQueue, runQueueUntil } from '../support/wp';

test.beforeEach(() => resetSite());

/** Reloads the page; whether it then lists `count` runs with this status (the runs table renders after load). */
const hasRuns = async (page: Page, status: string, count: number) => {
  await page.reload();
  try {
    await expect(page.getByRole('row').filter({ hasText: status })).toHaveCount(count, { timeout: 5_000 });
    return true;
  } catch {
    return false;
  }
};

/** Opens the mail log and counts this email's rows. */
const mailRows = async (page: Page, email: string, subject: string) => {
  await openMailLog(page);
  return mailRow(page, email, subject).count();
};

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

  const email = uniqueEmail();
  const shopper = await guestPage(browser, baseURL);
  await addToCart(shopper);
  await placeOrder(shopper, email);
  completeOrder(email);
  await runQueueUntil(() => hasRuns(page, 'Completed', 1));

  await expect(page.getByRole('row').filter({ hasText: 'Completed' })).toHaveCount(1);

  await openMailLog(page);
  await expect(mailRow(page, email, FIRST_ORDER_SUBJECT)).toBeVisible();
});

test('a deactivated workflow does not run', async ({ page, browser, baseURL }) => {
  await useTemplate(page, FIRST_ORDER);
  await activate(page);
  await page.getByRole('button', { name: 'Deactivate' }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Deactivate' }).click();
  await expect(page.getByText('Inactive').first()).toBeVisible();

  const email = uniqueEmail();
  const shopper = await guestPage(browser, baseURL);
  await addToCart(shopper);
  await placeOrder(shopper, email);
  completeOrder(email);
  runQueue();

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
  // The reminder waits before its email; only then can its wait be ended.
  await runQueueUntil(() => hasRuns(page, 'Waiting', 1));
  await openMailLog(page);
  await expect(mailRow(page, email, CHECKOUT_SUBJECT)).toHaveCount(0);

  endWaits();
  await runQueueUntil(async () => (await mailRows(page, email, CHECKOUT_SUBJECT)) > 0);
  await mailRow(page, email, CHECKOUT_SUBJECT).getByRole('link').click();
  const link = page.frameLocator('iframe[title="Mail body"]').locator('a[href*="yaymatik_cart"]').first();
  const restoreUrl = await link.getAttribute('href');

  const returning = await guestPage(browser, baseURL);
  await returning.goto(restoreUrl as string);
  await returning.goto('/cart/');
  // Inside the items table: an empty cart also lists Seed Tee, under "New in store".
  await expect(returning.locator('.wc-block-cart-items').getByText('Seed Tee').first()).toBeVisible();
});

test('an order through classic checkout runs the workflow', async ({ page, browser, baseURL }) => {
  await useTemplate(page, FIRST_ORDER);
  await activate(page);

  const email = uniqueEmail();
  const shopper = await guestPage(browser, baseURL);
  await addToCart(shopper);
  await placeClassicOrder(shopper, email);
  completeOrder(email);
  await runQueueUntil(async () => (await mailRows(page, email, FIRST_ORDER_SUBJECT)) > 0);

  await expect(mailRow(page, email, FIRST_ORDER_SUBJECT)).toBeVisible();
});

test('an unsubscribe link stops the next email', async ({ page, browser, baseURL }) => {
  // Two workflows, two orders and two queue waits: over the default 90 s when jobs land late.
  test.slow();
  await useTemplate(page, FIRST_ORDER);
  await activate(page);
  await useTemplate(page, REVIEW_REQUEST);
  await activate(page);

  // Two shoppers; only the first unsubscribes, so the second shows the review email does go out.
  const leaving = uniqueEmail();
  // Not `stays-${leaving}`: a row filter by text would then match both addresses.
  const staying = leaving.replace('shopper-', 'stays-');
  for (const email of [leaving, staying]) {
    const shopper = await guestPage(browser, baseURL);
    await addToCart(shopper);
    await placeOrder(shopper, email);
    completeOrder(email);
  }

  // Each review request waits for its email; the thank-yous are already sent.
  await runQueueUntil(() => hasRuns(page, 'Waiting', 2));
  await expect(page.getByRole('row').filter({ hasText: 'Waiting' })).toHaveCount(2);
  await openMailLog(page);
  await mailRow(page, leaving, FIRST_ORDER_SUBJECT).getByRole('link').click();
  const link = page.frameLocator('iframe[title="Mail body"]').locator('a[href*="yaymatik_unsub"]').first();
  const unsubscribeUrl = await link.getAttribute('href');

  const reader = await guestPage(browser, baseURL);
  await reader.goto(unsubscribeUrl as string);
  await reader.getByRole('button', { name: 'Unsubscribe' }).click();
  await expect(reader.getByText('You’re unsubscribed')).toBeVisible();

  endWaits();
  await runQueueUntil(async () => (await mailRows(page, staying, REVIEW_SUBJECT)) > 0);
  await expect(mailRow(page, staying, REVIEW_SUBJECT)).toHaveCount(1);
  await expect(mailRow(page, leaving, REVIEW_SUBJECT)).toHaveCount(0);
});
