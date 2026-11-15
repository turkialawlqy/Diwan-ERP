import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

/* Documents can be attached while the invoice is still being written (staged, then kept with it on save). */
test('attach files on the new sales and purchase invoice pages', async ({ page }) => {
    const errors = watchErrors(page);
    for (const url of ['/sales/invoices/create', '/purchases/invoices/create']) {
        await page.goto(url);
        const panel = page.locator('[data-attachments]').first();
        await expect(panel).toBeVisible();
        await panel.locator('[data-attachment-input]').setInputFiles([
            { name: 'delivery-note.txt', mimeType: 'text/plain', buffer: Buffer.from('delivery note 1') },
            { name: 'supplier-bill.txt', mimeType: 'text/plain', buffer: Buffer.from('supplier bill 2') },
        ]);
        await expect(panel.locator('[data-attachment-row]')).toHaveCount(2);
        await expect(panel.getByText('delivery-note.txt')).toBeVisible();
    }
    expect(errors).toEqual([]);
});
