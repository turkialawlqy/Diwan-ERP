import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

/* HR attendance: tick employees, apply one status to all of them, save once. */
test('apply one attendance status to several employees', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/hr/attendance');
    await expect(page.locator('[data-att-bulk]')).toBeVisible();
    const rows = page.locator('[data-att-row]');
    if ((await rows.count()) === 0) test.skip(true, 'no active employees in the seed');

    await page.locator('[data-att-select-all]').click();
    await page.locator('[data-att-present]').click();
    const selects = page.locator('[data-att-row] select');
    for (let i = 0; i < Math.min(await selects.count(), 3); i++) await expect(selects.nth(i)).toHaveValue('present');
    expect(errors).toEqual([]);
});
