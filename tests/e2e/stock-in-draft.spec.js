import { test, expect } from '@playwright/test';
import { pickFromLookup, watchErrors } from './helpers';

/*
 * The warehouse keeper adds only an item and a quantity: the stock enters at once and a purchase draft opens.
 * Accounting then adds the supplier, the date and the real price, and the draft becomes a supplier invoice.
 */
test('stock-in by the keeper becomes a supplier invoice completed by accounting', async ({ page }) => {
    const errors = watchErrors(page);

    await page.goto('/inventory/stock-in/create');
    const search = page.locator('[data-stockin-line] input').first();
    await search.click();
    await search.pressSequentially('ش', { delay: 30 });
    await page.locator('div.absolute button').first().click();
    await page.locator('[data-stockin-qty]').fill('3');
    await page.locator('[data-stockin-save]').click();
    await expect(page).toHaveURL(/\/inventory\/stock-in$/);
    await expect(page.locator('[data-stockin-row]').first()).toContainText('PDR-');

    await page.goto('/purchases/drafts');
    await expect(page.locator('[data-draft-row]').first()).toBeVisible();
    await page.locator('[data-draft-complete]').first().click();
    await expect(page).toHaveURL(/\/purchases\/drafts\/\d+\/complete$/);

    await pickFromLookup(page, page.getByPlaceholder('ابحث بالاسم أو الجوال أو الرقم الضريبي...'), 'شركة');
    await page.locator('[data-draft-number]').fill(`BILL-${Date.now().toString().slice(-6)}`);
    await page.locator('[data-draft-price]').first().fill('25');
    await page.locator('[data-draft-save]').click();
    await expect(page).toHaveURL(/\/purchases\/invoices\/\d+$/);

    await page.goto('/purchases/drafts?status=completed');
    await expect(page.locator('[data-draft-row]').first()).toBeVisible();
    expect(errors).toEqual([]);
});
