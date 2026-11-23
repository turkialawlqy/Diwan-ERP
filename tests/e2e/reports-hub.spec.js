import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

/* «كل التقارير»: the hub lists the reports as cards, the sidebar holds them under Reports with section headings. */
test('reports hub and the sidebar reports group', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/reports');
    await expect(page.locator('[data-reports-hub]')).toBeVisible();
    await expect(page.locator('[data-reports-card]').first()).toBeVisible();
    await expect(page.locator('[data-report-link]', { hasText: 'ميزان المراجعة' }).first()).toBeVisible();

    await page.locator('[data-reports-search]').fill('ميزان المراجعة');
    await expect(page.locator('[data-report-link]')).toHaveCount(1);
    await page.locator('[data-report-link]').first().click();
    await expect(page).toHaveURL(/\/accounting\/trial-balance/);

    // The financial statements are no longer a group of their own; the sidebar shows section headings inside Reports.
    await expect(page.locator('aside').getByRole('button', { name: 'القوائم المالية', exact: true })).toHaveCount(0);
    await expect(page.locator('[data-nav-section]').first()).toBeVisible();
    expect(errors).toEqual([]);
});
