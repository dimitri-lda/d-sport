import { expect, test } from '@playwright/test';

/**
 * Storefront happy paths against the disposable fixture stack from
 * scripts/e2e.sh. Serial: every scenario shares one isolated test database.
 * Selectors are accessible roles/labels only — never implementation hooks.
 */

const PRODUCT_NAME = 'Cloudswift 4';
const ADMIN_EMAIL = 'admin@example.test';
const ADMIN_PASSWORD = 'SportE!2026';

// Filled by scenario 2 so the admin scenario can target a known pending order.
let registeredOrderNumber = '';

async function fillCheckoutForm(
  page: import('@playwright/test').Page,
  email: string,
) {
  await page.getByLabel('Email').fill(email);
  await page.getByLabel('Phone').fill('+49 170 0000000');
  await page.getByLabel('First name').fill('Ada');
  await page.getByLabel('Last name').fill('Runner');
  await page.getByLabel('Address line 1').fill('1 Teststrasse');
  await page.getByLabel('City').fill('Berlin');
  await page.getByLabel('Postal code').fill('10115');
  await page
    .getByRole('region', { name: 'Shipping address' })
    .getByLabel('Country')
    .selectOption('DE');
  await page.getByRole('radio', { name: /Standard/ }).check();
}

async function placeOrder(
  page: import('@playwright/test').Page,
): Promise<string> {
  await page.getByRole('button', { name: 'Place order' }).click();
  await expect(page).toHaveURL(/\/orders\/SP-[0-9A-F]{8}$/, {
    timeout: 15_000,
  });
  const orderNumber = page.url().split('/orders/')[1];
  await expect(page.getByText(orderNumber).first()).toBeVisible();
  return orderNumber;
}

test.describe.serial('storefront', () => {
  test('guest browses, buys and reads the order', async ({ page }) => {
    await page.goto('/');
    await expect(
      page.getByRole('heading', { name: 'Gear for the long game.' }),
    ).toBeVisible();

    await page.getByRole('link', { name: 'Shop the collection' }).click();
    await expect(page).toHaveURL(/\/shop$/);
    await page
      .getByRole('link', { name: /Cloudswift 4/ })
      .first()
      .click();
    await expect(page).toHaveURL(/\/shop\/cloudswift-4$/);

    await page.getByRole('button', { name: 'Add to cart' }).click();
    await expect(page.getByText('Added to cart')).toBeVisible();

    await page.getByRole('link', { name: 'Cart' }).first().click();
    await expect(page).toHaveURL(/\/cart$/);
    await expect(page.getByText(PRODUCT_NAME).first()).toBeVisible();
    await expect(page.getByLabel('Quantity: 1')).toBeVisible();

    await page.getByRole('link', { name: 'Proceed to checkout' }).click();
    await expect(page).toHaveURL(/\/checkout$/);
    await fillCheckoutForm(page, 'guest@example.test');

    const orderNumber = await placeOrder(page);
    expect(orderNumber).toMatch(/^SP-/);
    await expect(
      page.getByRole('heading', { name: `Order ${orderNumber}` }),
    ).toBeVisible();
  });

  test('registered buyer checks out and owns the order', async ({ page }) => {
    const email = `e2e-${Date.now()}@example.test`;

    await page.goto('/register');
    await page.getByLabel('Display name').fill('E2E Buyer');
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Password', { exact: true }).fill('SportE!2026-e2e');
    await page.getByLabel('Confirm password').fill('SportE!2026-e2e');
    await page.getByLabel('I accept the terms of service').check();
    await page.getByRole('button', { name: 'Create account' }).click();
    await expect(page).toHaveURL(/\/profile$/, { timeout: 15_000 });
    await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible();

    await page.getByRole('link', { name: 'Shop' }).first().click();
    await page
      .getByRole('link', { name: /Cloudswift 4/ })
      .first()
      .click();
    await page.getByRole('button', { name: 'Add to cart' }).click();
    await page.getByRole('link', { name: 'Cart' }).first().click();
    await page.getByRole('link', { name: 'Proceed to checkout' }).click();
    await fillCheckoutForm(page, email);

    registeredOrderNumber = await placeOrder(page);
    await expect(
      page.getByRole('heading', { name: `Order ${registeredOrderNumber}` }),
    ).toBeVisible();
  });

  test('admin pays the newest pending order', async ({ page }) => {
    expect(registeredOrderNumber).toMatch(/^SP-/);

    await page.goto('/login');
    await page.getByLabel('Email').fill(ADMIN_EMAIL);
    await page.getByLabel('Password').fill(ADMIN_PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.getByRole('button', { name: 'Log out' })).toBeVisible({
      timeout: 15_000,
    });

    await page.goto('/admin/orders');
    await expect(
      page.getByRole('heading', { name: 'Orders' }).first(),
    ).toBeVisible();
    // URL-reflected filter: typing the number narrows to exactly that order.
    await page.getByPlaceholder('Number or email').fill(registeredOrderNumber);

    await page.getByRole('link', { name: registeredOrderNumber }).click();
    await expect(page.getByText(registeredOrderNumber).first()).toBeVisible();

    await page.getByLabel('Next status').selectOption('paid');
    await page.getByRole('button', { name: 'Apply' }).click();
    await expect(page.getByText('Paid').first()).toBeVisible({
      timeout: 15_000,
    });
  });

  test('mobile viewport renders the storefront without overflow', async ({
    page,
  }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/');

    await page.getByRole('button', { name: '☰' }).click();
    const mobileNav = page.locator('#mobile-nav');
    await expect(mobileNav.getByRole('link', { name: 'Shop' })).toBeVisible();
    await expect(mobileNav.getByRole('link', { name: 'Cart' })).toBeVisible();
    await expect(mobileNav.getByRole('link', { name: 'Log in' })).toBeVisible();

    await page.goto('/shop');
    await expect(
      page.getByRole('link', { name: /Cloudswift 4/ }).first(),
    ).toBeVisible();
    const overflows = await page.evaluate(
      () =>
        document.documentElement.scrollWidth >
        document.documentElement.clientWidth,
    );
    expect(overflows).toBe(false);
  });
});
