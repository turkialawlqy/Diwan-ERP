import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers.js';

/* POS: product cards carry a picture slot, and the selected cart line is changed from the keyboard. */
test('pos: keyboard changes the quantity of the selected line and cards show a picture slot', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/pos', { waitUntil: 'networkidle' });
    if (await page.getByRole('button', { name: 'فتح الجلسة' }).count()) {
        await page.getByRole('button', { name: 'فتح الجلسة' }).click();
        await page.waitForLoadState('networkidle');
    }

    const card = page.locator('div.grid.grid-cols-2 > div.cursor-pointer').first();
    await expect(card.locator('[data-product-image]')).toBeVisible();
    await card.click();
    const qty = page.locator('[data-cart-qty]').first();
    await expect(qty).toHaveValue('1');

    await page.locator('body').click({ position: { x: 5, y: 300 } });
    await page.keyboard.press('+');
    await page.keyboard.press('+');
    await expect(qty).toHaveValue('3');
    await page.keyboard.press('-');
    await expect(qty).toHaveValue('2');

    // Typing the quantity into its box.
    await qty.fill('7');
    await qty.press('Enter');
    await expect(qty).toHaveValue('7');

    // «4*code» through the search box adds four at once.
    const sku = (await card.locator('span.font-mono').first().innerText()).trim();
    await page.getByPlaceholder(/امسح الباركود/).fill(`4*${sku}`);
    await page.getByPlaceholder(/امسح الباركود/).press('Enter');
    await expect(page.locator('[data-cart-qty]').first()).toHaveValue('11');

    // Delete removes the selected line.
    await page.locator('body').click({ position: { x: 5, y: 300 } });
    await page.keyboard.press('Delete');
    await expect(page.locator('[data-cart-qty]')).toHaveCount(0);
    expect(errors).toEqual([]);
});
