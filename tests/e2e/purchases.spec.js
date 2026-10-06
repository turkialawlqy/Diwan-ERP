import { test, expect } from '@playwright/test';
import { pickFromLookup, watchErrors } from './helpers';

test('purchase order: pick supplier and product, save and submit', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/purchases/orders/create');

    await pickFromLookup(page, page.getByPlaceholder('ابحث بالاسم أو الجوال أو الرقم الضريبي...'), 'شركة');
    await expect(page.getByRole('button', { name: '✕' })).toBeVisible();

    const productSearch = page.getByPlaceholder('ابحث عن صنف بالاسم أو الرمز أو الباركود...');
    await productSearch.click();
    await productSearch.pressSequentially('ش', { delay: 30 });
    const option = page.locator('div.absolute button').first();
    await expect(option).toBeVisible();
    await option.click();
    await expect(page.locator('table tbody tr')).toHaveCount(1);

    await page.getByRole('button', { name: 'حفظ وإرسال للاعتماد' }).click();
    await expect(page).toHaveURL(/\/purchases\/orders\/\d+$/);
    expect(errors).toEqual([]);
});
