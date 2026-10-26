import { test, expect } from '@playwright/test';
import path from 'node:path';
import { watchErrors } from './helpers';

const LOGO = path.resolve('tests/e2e/fixtures/logo.png');

test('logo uploaded in the template designer shows on the printed invoice', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/templates');
    // The quick (section-based) A4 designer, not a free-form one.
    const designer = await page.locator('div.group').filter({ hasText: 'فاتورة ضريبية A4 (ZATCA)' }).first().locator('a[href*="/designer"]').getAttribute('href');
    await page.goto(designer);
    await page.getByRole('button', { name: 'الشعار', exact: true }).click();
    await page.locator('input[type=file]').setInputFiles(LOGO);
    await expect(page.locator('img[alt="Company Logo"], img[src*="/company/logo"]').first()).toBeVisible();

    await page.goto('/sales/invoices');
    const invoice = await page.locator('a[href*="/sales/invoices/"]').evaluateAll((as) => as.map((a) => a.getAttribute('href')).find((h) => /\/sales\/invoices\/\d+$/.test(new URL(h, location.href).pathname)));
    await page.goto(`${invoice}/print`);
    const logo = page.locator('img[src*="/company/logo"]').first();
    await expect(logo).toBeVisible();
    expect(await logo.evaluate((img) => img.naturalWidth)).toBeGreaterThan(0);
    expect(errors).toEqual([]);
});

test('product with a zero-rated VAT category can be created and edited', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/inventory/products/create');
    await page.getByPlaceholder('مثال: لابتوب ديل انسبيرون...').fill('صنف تصدير اختبار');
    await page.locator('select').filter({ has: page.locator('option[value="Z"]') }).first().selectOption('Z');
    const reason = page.locator('select').filter({ has: page.locator('option[value="VATEX-SA-32"]') }).first();
    await reason.selectOption('VATEX-SA-32');
    await page.getByRole('button', { name: 'حفظ المنتج' }).first().click();
    await expect(page).not.toHaveURL(/\/create$/);

    await page.goto('/inventory/products?search=' + encodeURIComponent('صنف تصدير اختبار'));
    await page.locator('tbody tr').filter({ hasText: 'صنف تصدير اختبار' }).locator('button').last().click();
    await page.getByText('تعديل المنتج').first().click();
    await expect(page).toHaveURL(/\/edit$/);
    await expect(page.locator('select').filter({ has: page.locator('option[value="VATEX-SA-32"]') }).first()).toHaveValue('VATEX-SA-32');
    expect(errors).toEqual([]);
});

test('HR settings: change a GOSI rate, save, then restore defaults', async ({ page }) => {
    const errors = watchErrors(page);
    const gosi = () => page.locator('div.rounded-2xl').filter({ has: page.getByRole('heading', { name: 'التأمينات الاجتماعية' }) });
    const rate = () => gosi().getByLabel(/^سعودي — حصة الموظف/);

    await page.goto('/hr/settings');
    await rate().fill('10');
    await gosi().getByRole('button', { name: 'حفظ', exact: true }).click();
    await expect(gosi()).toContainText('معدّلة للمنشأة');
    await page.reload();
    await expect(rate()).toHaveValue(/^10(\.0+)?$/);

    page.once('dialog', (d) => d.accept());
    await gosi().getByRole('button', { name: 'استعادة النظامي' }).click();
    await expect(gosi()).not.toContainText('معدّلة للمنشأة');
    await page.reload();
    await expect(rate()).toHaveValue('9.75');
    expect(errors).toEqual([]);
});
