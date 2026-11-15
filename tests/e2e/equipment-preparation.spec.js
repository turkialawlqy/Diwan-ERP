import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

/* Daily equipment preparation: the day's sheet, a preparation with two cards attached, and the sheet updates. */
test('prepare a piece of equipment with two cards attached', async ({ page }) => {
    const errors = watchErrors(page);
    // A piece of equipment to prepare.
    const code = `PRP-${Date.now().toString().slice(-6)}`;
    await page.goto('/equipment');
    await page.getByRole('button', { name: 'معدة جديدة' }).click();
    const dialog = page.locator('div.fixed.inset-0').last();
    await dialog.getByLabel('رمز المعدة').fill(code);
    await dialog.getByLabel('اسم المعدة').fill('حفار تحضير');
    await dialog.getByRole('button', { name: 'حفظ' }).click();
    await expect(page).toHaveURL(/\/equipment\/\d+$/);

    await page.goto('/equipment/preparations');
    await expect(page.locator('[data-prep-sheet]')).toBeVisible();
    const missingBefore = await page.locator('[data-prep-do]').count();
    expect(missingBefore).toBeGreaterThan(0);

    await page.locator('[data-prep-row]', { hasText: code }).locator('[data-prep-do]').click();
    await expect(page.locator('[data-prep-save]')).toBeVisible();
    await page.locator('[data-attachment-input]').setInputFiles([
        { name: 'card-morning.txt', mimeType: 'text/plain', buffer: Buffer.from('prep card 1') },
        { name: 'card-checklist.txt', mimeType: 'text/plain', buffer: Buffer.from('prep card 2') },
    ]);
    await expect(page.locator('[data-attachment-row]')).toHaveCount(2);
    await page.locator('[data-prep-save]').click();

    await expect(page).toHaveURL(/\/equipment\/preparations/);
    await expect(page.locator('[data-prep-do]')).toHaveCount(missingBefore - 1);
    expect(errors).toEqual([]);
});
