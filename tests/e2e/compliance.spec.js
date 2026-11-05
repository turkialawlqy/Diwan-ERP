import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers.js';

/* Saudi compliance screens: withholding fields on a supplier, the withholding return and the project WIP card. */
test('compliance: supplier withholding fields, withholding return and project WIP card render', async ({ page }) => {
    const errors = watchErrors(page);

    await page.goto('/purchases/suppliers/create', { waitUntil: 'networkidle' });
    await page.locator('[data-non-resident]').check();
    await expect(page.locator('[data-wht-fields] select')).toBeVisible();

    await page.goto('/accounting/reports/withholding', { waitUntil: 'networkidle' });
    await expect(page.getByText('إقرار ضريبة الاستقطاع').first()).toBeVisible();
    await expect(page.locator('[data-export-buttons]')).toBeVisible();

    await page.goto('/projects', { waitUntil: 'networkidle' });
    const first = page.locator('a[href^="/projects/"]').filter({ hasNotText: 'جديد' }).first();
    if (await first.count()) {
        await first.click();
        await page.waitForLoadState('networkidle');
        await expect(page.locator('[data-wip]')).toBeVisible();
        await page.locator('[data-open-progress]').click();
        await expect(page.locator('[data-progress-row]').first()).toBeVisible();
    }
    expect(errors).toEqual([]);
});
