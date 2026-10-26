import { test, expect } from '@playwright/test';
import { pickFromLookup, watchErrors } from './helpers';

test('create a recurring invoice and issue the due period', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/sales/recurring');
    await page.getByRole('button', { name: '+ فاتورة دورية' }).click();
    const dialog = page.locator('div.fixed.inset-0').last();

    await dialog.getByLabel('اسم العقد').fill('صيانة شهرية — اختبار');
    await pickFromLookup(page, dialog.getByPlaceholder('ابحث بالاسم أو الجوال أو الرقم الضريبي...'), 'شركة');
    await dialog.getByLabel('تاريخ أول إصدار').fill(new Date().toISOString().substring(0, 10));
    await dialog.getByRole('button', { name: '+ سطر حر' }).click();
    const line = dialog.locator('tbody tr').first().locator('input');
    await line.nth(0).fill('عقد صيانة');
    await line.nth(2).fill('1500');
    await dialog.getByRole('button', { name: 'حفظ', exact: true }).click();
    await expect(dialog).toBeHidden();

    const card = page.locator('div.rounded-2xl').filter({ hasText: 'صيانة شهرية — اختبار' }).first();
    await expect(card).toContainText('نشطة');
    await card.getByRole('button', { name: 'إصدار المستحق الآن' }).click();
    await expect(page.locator('body')).toContainText('تم إصدار 1 فاتورة');
    await expect(card).toContainText('INV-');
    expect(errors).toEqual([]);
});

test('insights: every tab renders with its charts', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/reports/insights');
    await expect(page.locator('.apexcharts-canvas')).toHaveCount(2);
    for (const tab of ['أعمار الذمم', 'الربحية', 'الموازنة مقابل الفعلي', 'التدفق النقدي المتوقع']) {
        await page.getByRole('button', { name: tab }).click();
        await expect(page.locator('section:visible')).toHaveCount(1);
    }
    expect(errors).toEqual([]);
});
