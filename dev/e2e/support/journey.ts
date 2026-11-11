import { expect, type Browser, type Page } from '@playwright/test';

export const FIRST_ORDER = 'first-order-thank-you-discount';
export const FIRST_ORDER_SUBJECT = 'Thanks for your first order';
export const CHECKOUT_REMINDER = 'checkout-reminder';
export const CHECKOUT_SUBJECT = 'Did something go wrong at checkout?';
export const REVIEW_REQUEST = 'review-request';
export const REVIEW_SUBJECT = 'How did your order go?';

export const uniqueEmail = () => `shopper-${Date.now()}@yay-seed.test`;

export async function useTemplate(page: Page, id: string) {
  await page.goto(`/wp-admin/admin.php?page=yaymatik#/workflows/templates/${id}`);
  await page.getByRole('button', { name: 'Use template' }).first().click();
  await page.waitForURL(/#\/workflows\/\d+\/edit/);
}

/** From the editor. Ends on the workflow's overview. */
export async function activate(page: Page) {
  await page.getByRole('button', { name: 'Activate', exact: true }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Activate', exact: true }).click();
  await expect(page.getByRole('button', { name: 'Activate', exact: true })).toHaveCount(0);
  await page.goto(page.url().replace(/\/edit$/, ''));
  await expect(page.getByText(/^Active/).first()).toBeVisible();
}

/** A browser with no login, as a shopper. */
export async function guestPage(browser: Browser, baseURL: string | undefined) {
  const context = await browser.newContext({
    baseURL,
    storageState: { cookies: [], origins: [] },
  });
  return context.newPage();
}

export async function addToCart(page: Page) {
  await page.goto('/product/seed-tee/');
  await page.getByRole('button', { name: 'Add to cart', exact: true }).click();
  await expect(page.getByText('has been added to your cart')).toBeVisible();
}

/** Block checkout up to the email, which is when the store learns who the cart belongs to. */
export async function enterEmail(page: Page, email: string) {
  await page.goto('/checkout/');
  await page.getByLabel('Email address').fill(email);
  await page.getByLabel('Email address').blur();
}

export async function placeOrder(page: Page, email: string) {
  await enterEmail(page, email);
  await page.getByLabel('First name').fill('Seed');
  await page.getByLabel('Last name').fill('Shopper');
  await page.getByLabel('Address', { exact: true }).fill('1 Seed St');
  await page.getByLabel('City').fill('San Jose');
  await page.getByLabel('ZIP Code').fill('95112');
  await page.getByRole('button', { name: 'Place order' }).click();
  await page.waitForURL(/order-received/);
}

/** The seed's "Classic checkout" page: WooCommerce's shortcode form, not the block. */
export async function placeClassicOrder(page: Page, email: string) {
  await page.goto('/classic-checkout/');
  await page.locator('#billing_email').fill(email);
  await page.locator('#billing_first_name').fill('Seed');
  await page.locator('#billing_last_name').fill('Shopper');
  await page.locator('#billing_address_1').fill('1 Seed St');
  await page.locator('#billing_city').fill('San Jose');
  await page.locator('#billing_postcode').fill('95112');
  await page.locator('#billing_phone').fill('4085550100');
  // The address fields refresh the order review, which covers the form and replaces the button.
  await expect(page.locator('.blockUI')).toHaveCount(0);
  await page.locator('#place_order').click();
  await page.waitForURL(/order-received/);
}

export const mailRow = (page: Page, email: string, subject: string) =>
  page.getByRole('row').filter({ hasText: email }).filter({ hasText: subject });

export const openMailLog = (page: Page) => page.goto('/wp-admin/tools.php?page=yay-mail-log');
