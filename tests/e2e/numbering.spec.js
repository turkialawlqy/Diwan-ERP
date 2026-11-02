import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers.js';

/*
 * «دليل الترميز»: an unused prefix is edited in the page and saved; a prefix that
 * records already carry (the demo customers C-…) is shown locked.
 */
test('numbering guide: edit an unused prefix, a used one is locked', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/settings/numbering', { waitUntil: 'networkidle' });

    // Customers exist in the demo data, so their prefix is locked and has no input.
    const customer = page.locator('tr[data-type="customer"]');
    await expect(customer.locator('[data-locked]')).toBeVisible();
    await expect(customer.locator('[data-prefix]')).toHaveCount(0);

    // Stock counts are unused: change the prefix and save.
    const count = page.locator('tr[data-type="stock_count"][data-year="0"]');
    await expect(count.locator('[data-locked]')).toHaveCount(0);
    await count.locator('[data-prefix]').fill('INVT-');
    await count.locator('[data-save]').click();
    await expect(page.locator('tr[data-type="stock_count"][data-year="0"] [data-prefix]')).toHaveValue('INVT-');

    await page.reload({ waitUntil: 'networkidle' });
    await expect(page.locator('tr[data-type="stock_count"][data-year="0"] [data-prefix]')).toHaveValue('INVT-');
    await expect(page.locator('tr[data-type="stock_count"][data-year="0"]')).toContainText('INVT-');

    // A prefix shared with another document of the same register is refused with a message.
    const pos = page.locator('tr[data-type="pos_invoice"][data-year="0"]');
    await pos.locator('[data-prefix]').fill('INV-');
    await pos.locator('[data-save]').click();
    await expect(page.locator('[data-numbering-errors]')).toBeVisible();

    expect(errors).toEqual([]);
});
