import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers.js';

/*
 * POS without a connection: the sale is kept on the device with a temporary receipt, the till shows what waits,
 * and when the link returns the sale is sent once and the queue empties.
 */
test('pos: a sale made offline is kept, then sent when the connection returns', async ({ page, context }) => {
    const errors = watchErrors(page);

    await page.goto('/pos', { waitUntil: 'networkidle' });
    if (await page.getByRole('button', { name: 'فتح الجلسة' }).count()) {
        await page.getByRole('button', { name: 'فتح الجلسة' }).click();
        await page.waitForLoadState('networkidle');
    }
    await expect(page.locator('[data-pos-link]')).toBeVisible();
    await page.locator('div.grid.grid-cols-2 > div.cursor-pointer').first().click(); // the cart is filled while online

    // The link drops: the sale still goes through, on this device.
    await context.setOffline(true);
    await expect(page.locator('[data-pos-link]')).toContainText(/دون اتصال|Offline/);
    await page.locator('[data-pos-cash]').first().click();
    await page.locator('[data-cash-confirm]').first().click();

    await expect(page.locator('[data-receipt-offline]')).toBeVisible();
    await expect(page.locator('[data-pos-pending]')).toBeVisible();
    await page.locator('#thermal-receipt').waitFor();
    await expect(page.locator('#thermal-receipt')).toContainText('OFF-');

    // The link returns: the queue is sent and empties.
    await context.setOffline(false);
    await expect(page.locator('[data-pos-pending]')).toHaveCount(0, { timeout: 30000 });
    expect(errors.filter((e) => !/net::ERR_INTERNET_DISCONNECTED|Failed to fetch|Network Error/i.test(String(e)))).toEqual([]);
});
