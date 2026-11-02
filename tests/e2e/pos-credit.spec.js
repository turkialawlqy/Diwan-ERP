import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers.js';

/*
 * POS credit sale: the till starts on the cash customer, «بيع آجل» asks for a named
 * customer, shows his balance, and the receipt prints what stays on his account.
 */
test('pos: credit sale needs a customer, takes a down payment and prints the remainder', async ({ page }) => {
    const errors = watchErrors(page);

    // The setting is on for the demo company; make sure and open a session.
    await page.goto('/pos', { waitUntil: 'networkidle' });
    if (await page.getByRole('button', { name: 'فتح الجلسة' }).count()) {
        await page.getByRole('button', { name: 'فتح الجلسة' }).click();
        await page.waitForLoadState('networkidle');
    }

    // Default customer is the cash customer, never "the latest customer".
    const select = page.locator('select').first();
    await expect(select.locator('option:checked')).toHaveText('عميل نقدي (عام)');

    await page.locator('div.grid.grid-cols-2 > div.cursor-pointer').first().click();
    await page.locator('[data-pos-credit]').click();
    await expect(page.locator('[data-credit-need-customer]')).toBeVisible();
    await page.locator('[data-credit-modal] button').first().click(); // close

    // Pick a customer and sell on credit with a small down payment.
    await select.selectOption({ index: 1 });
    await page.locator('[data-pos-credit]').click();
    await expect(page.locator('[data-credit-need-customer]')).toHaveCount(0);
    await page.locator('[data-credit-down]').fill('1');
    await page.locator('[data-credit-confirm]').click();

    await expect(page.locator('[data-receipt-remaining]')).toBeVisible();
    await expect(page.locator('#thermal-receipt')).toContainText('آجل');
    expect(errors).toEqual([]);
});
