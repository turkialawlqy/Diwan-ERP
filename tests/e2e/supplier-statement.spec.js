import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers.js';

/* Suppliers: a statement page from the list, and one payment spread over several open invoices. */
test('suppliers: statement opens from the list and the payment form can spread an amount', async ({ page }) => {
    const errors = watchErrors(page);

    await page.goto('/purchases/suppliers', { waitUntil: 'networkidle' });
    const link = page.locator('[data-supplier-statement]').first();
    await expect(link).toBeVisible();
    await link.click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('[data-closing]')).toBeVisible();
    await expect(page.locator('[data-export-buttons]')).toBeVisible();

    await page.goto('/purchases/payments/create', { waitUntil: 'networkidle' });
    await expect(page.getByText('سند صرف جديد').first()).toBeVisible();
    if (await page.locator('[data-allocation]').count()) {
        await page.locator('input[type="text"][data-num]').first().fill('50');
        await page.locator('[data-distribute]').click();
        expect(await page.locator('[data-allocation-row] input').count()).toBeGreaterThan(0);
    }
    expect(errors).toEqual([]);
});
