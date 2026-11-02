import { test, expect } from '@playwright/test';
import { watchErrors, pickFromLookup } from './helpers.js';

/* Customer subscriptions: a plan, a subscription on it (first invoice at once), then pause and cancel at period end. */
test('subscriptions: create a plan, subscribe a customer, pause and cancel at period end', async ({ page }) => {
    const errors = watchErrors(page);

    await page.goto('/sales/service-plans/create', { waitUntil: 'networkidle' });
    await page.locator('input').first().fill('باقة الاختبار');
    await page.getByRole('textbox').nth(1).fill('200');
    await page.locator('[data-save-plan]').click();
    await expect(page.locator('[data-plan]').filter({ hasText: 'باقة الاختبار' })).toBeVisible();

    await page.goto('/sales/subscriptions/create', { waitUntil: 'networkidle' });
    await pickFromLookup(page, page.getByPlaceholder('ابحث بالاسم').first(), 'ع');
    const plan = page.getByPlaceholder('اختر الخطة');
    await plan.click();
    await page.locator('div.fixed button, div.absolute button').filter({ hasText: 'باقة الاختبار' }).first().click();
    await expect(page.locator('[data-subscription-total]')).toContainText('230');
    await page.locator('[data-save-subscription]').click();

    await expect(page.locator('[data-status]')).toHaveText('نشط');
    await expect(page.getByText('الفواتير الصادرة')).toBeVisible();
    await expect(page.locator('table tbody tr').first()).toContainText('INV-');

    await page.locator('[data-pause]').click();
    await expect(page.locator('[data-status]')).toHaveText('موقوف');
    await page.locator('[data-resume]').click();
    await expect(page.locator('[data-status]')).toHaveText('نشط');

    await page.locator('[data-cancel]').click();
    await page.locator('[data-confirm-cancel]').click();
    await expect(page.getByText('سيُلغى في نهاية الفترة الحالية').first()).toBeVisible();
    await expect(page.locator('[data-keep]')).toBeVisible();

    expect(errors).toEqual([]);
});
