import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

test('sales invoice: pick customer and product, issue, open print with QR', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/sales/invoices/create');

    const customer = page.getByPlaceholder('ابحث برقم العميل، الاسم، الجوال أو اكتب اسماً جديداً...');
    await customer.click();
    await customer.fill('');
    await customer.pressSequentially('شركة', { delay: 30 });
    await page.locator('.py-1 > div.cursor-pointer').filter({ hasText: 'شركة' }).first().click();

    const product = page.getByPlaceholder('اختر أو انشئ منتج...').first();
    await product.click();
    await product.fill('');
    await product.pressSequentially('شاشة', { delay: 30 });
    const option = page.locator('.py-1 > div.cursor-pointer').filter({ hasText: 'شاشة' }).first();
    const productName = (await option.locator('.font-bold').first().innerText()).trim();
    await option.click();
    await expect(product).toHaveValue(productName);

    await page.getByRole('button', { name: 'حفظ وإصدار الفاتورة' }).first().click();
    await expect(page).toHaveURL(/\/sales\/invoices\/\d+$/);
    await expect(page.locator('body')).toContainText(productName);

    const id = page.url().match(/(\d+)$/)[1];
    await page.goto(`/sales/invoices/${id}/print`);
    const qr = page.locator('img[alt*="QR"]').first();
    await expect(qr).toBeVisible();
    expect(await qr.evaluate((img) => img.naturalWidth)).toBeGreaterThan(0);
    expect(errors).toEqual([]);
});

test('validation messages are in Arabic', async ({ page }) => {
    await page.goto('/sales/clients/create');
    await page.getByPlaceholder('مثال: شركة مدن الراقية المحدودة').fill('عميل ببريد خاطئ');
    await page.getByPlaceholder('client@company.com').fill('ليس-بريداً');
    await page.getByRole('button', { name: 'حفظ', exact: true }).click();
    await expect(page.locator('body')).toContainText('يجب أن يكون البريد الإلكتروني بريداً إلكترونياً صحيحاً.');
    await expect(page.locator('body')).not.toContainText('must be a valid email');
});
