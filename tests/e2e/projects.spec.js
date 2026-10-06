import { test, expect } from '@playwright/test';
import { pickFromLookup, watchErrors } from './helpers';

test.describe('Projects', () => {
    test('create a project with a customer and a BOQ', async ({ page }) => {
        const errors = watchErrors(page);
        await page.goto('/projects/create');

        await page.getByLabel('اسم المشروع').fill('مشروع اختبار المتصفح');
        await pickFromLookup(page, page.getByPlaceholder('ابحث بالاسم أو الجوال أو الرقم الضريبي...'), 'شركة');
        // The picked customer replaces the search box.
        await expect(page.getByRole('button', { name: '✕' })).toBeVisible();

        await page.getByRole('button', { name: '+ مجموعة (WBS)' }).click();
        await page.getByRole('button', { name: '+ بند' }).click();
        const rows = page.locator('tbody tr');
        await rows.nth(0).locator('input').nth(0).fill('5');
        await rows.nth(0).locator('input').nth(1).fill('بنود الواجهات الخارجية');
        const item = rows.nth(1).locator('input');
        await item.nth(0).fill('5-1');
        await item.nth(1).fill('معالجة الواجهات');
        await item.nth(2).fill('م.ط');
        await item.nth(3).fill('2080');
        await item.nth(4).fill('39');
        await expect(page.locator('tfoot')).toContainText('81,120.00');

        await page.getByRole('button', { name: 'حفظ المشروع' }).click();
        await expect(page).toHaveURL(/\/projects\/\d+$/);
        await expect(page.locator('body')).toContainText('مشروع اختبار المتصفح');
        expect(errors).toEqual([]);
    });

    test('saving without a customer explains what is missing', async ({ page }) => {
        await page.goto('/projects/create');
        await page.getByLabel('اسم المشروع').fill('مشروع بلا عميل');
        await page.getByRole('button', { name: 'حفظ المشروع' }).click();
        await expect(page.locator('body')).toContainText('اختر العميل (المالك) من نتائج البحث.');
        await expect(page).toHaveURL(/\/projects\/create$/);
    });

    test('BOQ rows without a description get an Arabic message with the row number', async ({ page }) => {
        await page.goto('/projects/create');
        await page.getByLabel('اسم المشروع').fill('مشروع بسطر ناقص');
        await pickFromLookup(page, page.getByPlaceholder('ابحث بالاسم أو الجوال أو الرقم الضريبي...'), 'شركة');
        await page.getByRole('button', { name: '+ بند' }).click();
        await page.locator('tbody tr').nth(0).locator('input').nth(0).fill('1-1');
        await page.getByRole('button', { name: 'حفظ المشروع' }).click();
        await expect(page.locator('body')).toContainText('جدول الكميات: السطر 1 بدون وصف.');
    });
});
