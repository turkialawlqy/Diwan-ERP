import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers.js';

/* Financial reports: Excel / PDF buttons download the report with the page's filters. */
test('reports: income statement and aging offer Excel and PDF downloads', async ({ page }) => {
    const errors = watchErrors(page);
    for (const [path, name] of [['/accounting/reports/income-statement', 'income-statement'], ['/accounting/reports/aging', 'aging'], ['/accounting/trial-balance', 'trial-balance']]) {
        await page.goto(path, { waitUntil: 'networkidle' });
        await expect(page.locator('[data-export-buttons]')).toBeVisible();

        const [excel] = await Promise.all([page.waitForEvent('download'), page.locator('[data-export-xlsx]').click()]);
        expect(excel.suggestedFilename()).toContain(name);
        expect(excel.suggestedFilename()).toMatch(/\.xlsx$/);

        const [pdf] = await Promise.all([page.waitForEvent('download'), page.locator('[data-export-pdf]').click()]);
        expect(pdf.suggestedFilename()).toMatch(/\.pdf$/);
    }
    expect(errors).toEqual([]);
});
