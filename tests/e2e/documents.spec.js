import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

test('invoice: download PDF and email it to the customer', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/sales/invoices');
    const href = await page.locator('a[href*="/sales/invoices/"]').evaluateAll((as) => as.map((a) => new URL(a.href).pathname).find((p) => /^\/sales\/invoices\/\d+$/.test(p)));
    await page.goto(href);

    const pdfHref = await page.getByRole('link', { name: 'PDF' }).getAttribute('href');
    const pdf = await page.evaluate(async (url) => {
        const r = await fetch(url);
        const head = new TextDecoder().decode((await r.arrayBuffer()).slice(0, 4));
        return { status: r.status, type: r.headers.get('content-type'), head };
    }, pdfHref);
    expect(pdf).toEqual({ status: 200, type: 'application/pdf', head: '%PDF' });

    await page.getByRole('button', { name: 'إرسال للعميل' }).click();
    const dialog = page.locator('div.fixed.inset-0').last();
    const to = dialog.getByLabel('إلى', { exact: true });
    await expect(dialog.getByLabel('نص الرسالة')).not.toHaveValue('');
    await to.fill('customer@example.com');
    await dialog.getByRole('button', { name: 'إرسال البريد' }).click();
    await expect(dialog).toContainText('تم إرسال البريد');
    await expect(dialog).toContainText('customer@example.com');
    expect(errors).toEqual([]);
});
