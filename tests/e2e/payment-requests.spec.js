import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

/* Payment requests: create as a draft, send for approval, open from the list. */
test('a payment request is created, sent and shown', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/finance/payment-requests/create');
    const form = page.locator('[data-payment-request-form]');
    await expect(form).toBeVisible();
    await form.locator('input[data-num]').first().fill('250');
    await form.locator('input:not([data-num]):not([type=hidden])').filter({ hasNot: page.locator('.dp') }).last().fill('ضيافة اجتماع');
    await page.locator('[data-prq-save-submit]').click();
    await expect(page).toHaveURL(/\/finance\/payment-requests\/\d+$/);
    await expect(page.locator('main')).toContainText('PRQ-');
    await page.goto('/finance/payment-requests');
    await expect(page.locator('table tbody tr').first()).toBeVisible();
    expect(errors).toEqual([]);
});
