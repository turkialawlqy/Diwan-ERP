import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

/* Equipment check-in/out on the daily sheet, and the four new equipment reports. */
test('equipment reports: attendance, maintenance detail, preparation and operators', async ({ page }) => {
    const errors = watchErrors(page);
    const code = `ATT-${Date.now().toString().slice(-6)}`;
    await page.goto('/equipment');
    await page.getByRole('button', { name: 'معدة جديدة' }).click();
    const dialog = page.locator('div.fixed.inset-0').last();
    await dialog.getByLabel('رمز المعدة').fill(code);
    await dialog.getByLabel('اسم المعدة').fill('حفار حضور');
    await dialog.getByRole('button', { name: 'حفظ' }).click();
    await expect(page).toHaveURL(/\/equipment\/\d+$/);

    // Time in and out on yesterday's sheet (today's sheet belongs to other specs).
    const yesterday = new Date(Date.now() - 86400000).toISOString().slice(0, 10);
    await page.goto(`/equipment/readings?date=${yesterday}`);
    const row = page.locator('tr', { hasText: code });
    if (await row.count()) {
        await row.locator('[data-check-in]').fill('07:30');
        await row.locator('[data-check-out]').fill('15:30');
        await page.getByRole('button', { name: 'حفظ القراءات' }).click();
    }

    for (const type of ['attendance', 'maintenance_log', 'preparation', 'operators']) {
        await page.goto(`/equipment/reports?type=${type}`);
        await expect(page.locator('table').first()).toBeVisible();
    }
    await page.goto('/equipment/reports?type=attendance');
    await expect(page.locator('[data-by-equipment]')).toBeVisible();
    expect(errors).toEqual([]);
});
