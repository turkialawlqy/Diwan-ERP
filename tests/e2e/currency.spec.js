import { test, expect } from '@playwright/test';
import { pickFromLookup, watchErrors } from './helpers';

test('USD invoice, then a receipt voucher at another rate', async ({ page }) => {
    const errors = watchErrors(page);

    // A dated rate for USD (the demo company already defines the currency).
    await page.goto('/settings/currencies');
    await page.getByLabel('التاريخ').fill('2026-01-01');
    await page.getByLabel(/^السعر \(1/).fill('3.75');
    await page.getByRole('button', { name: 'حفظ السعر' }).click();
    await expect(page.locator('body')).toContainText('3.750000');

    // Sales invoice in USD.
    await page.goto('/sales/invoices/create');
    const customer = page.getByPlaceholder('ابحث برقم العميل، الاسم، الجوال أو اكتب اسماً جديداً...');
    await customer.click();
    await customer.fill('');
    await customer.pressSequentially('شركة', { delay: 30 });
    await page.locator('.py-1 > div.cursor-pointer').filter({ hasText: 'شركة' }).first().click();
    const customerName = (await customer.inputValue()).trim();
    await page.getByLabel('العملة').selectOption('USD');
    const product = page.getByPlaceholder('اختر أو انشئ منتج...').first();
    await product.click();
    await product.fill('');
    await product.pressSequentially('شاشة', { delay: 30 });
    await page.locator('.py-1 > div.cursor-pointer').filter({ hasText: 'شاشة' }).first().click();
    await page.locator('select').filter({ has: page.locator('option[value="unpaid"]') }).first().selectOption('unpaid');
    await page.getByRole('button', { name: 'حفظ وإصدار الفاتورة' }).first().click();
    await expect(page).toHaveURL(/\/sales\/invoices\/\d+$/);
    await expect(page.locator('body')).toContainText('USD');
    await expect(page.locator('body')).toContainText('ما يعادل بالريال (سعر 3.7500)');
    const number = (await page.locator('h1 .font-mono').first().innerText()).trim();

    // Receipt voucher for that invoice at 3.76.
    await page.goto('/sales/payments');
    await page.getByRole('button', { name: '+ سند قبض جديد' }).click();
    const dialog = page.locator('div.fixed.inset-0').last();
    await pickFromLookup(page, dialog.getByPlaceholder('ابحث بالاسم أو الجوال أو الرقم الضريبي...'), customerName);
    const option = dialog.getByLabel('الفاتورة').locator('option', { hasText: number });
    await expect(option).toHaveCount(1);
    await dialog.getByLabel('الفاتورة').selectOption(await option.getAttribute('value'));
    await expect(dialog.getByLabel('العملة')).toHaveValue('USD');
    await dialog.getByLabel(/^سعر الصرف/).fill('3.76');
    await dialog.getByRole('button', { name: 'حفظ السند' }).click();
    await expect(dialog).toBeHidden();
    await expect(page.locator('tbody')).toContainText('USD');
    expect(errors).toEqual([]);
});
