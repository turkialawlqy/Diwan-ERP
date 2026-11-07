import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

/* Accounting control reports: the landing page, each report opens, filters apply, and Excel / PDF are offered. */
test('control reports: landing page, every report opens, filters and exports', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/accounting/controls');
    const cards = page.locator('[data-control]');
    await expect(cards).toHaveCount(11);

    for (const key of ['controls-exceptions', 'party-reconciliation', 'customer-balances', 'supplier-balances', 'cash-movement', 'dormant-accounts', 'unusual-expenses', 'budget-dimensions', 'forecast-vs-actual', 'closing-checklist', 'approval-flow']) {
        await page.goto(`/accounting/controls/${key}`);
        await expect(page.locator('[data-control-table]')).toBeVisible();
        await expect(page.locator('[data-export-xlsx]')).toBeVisible();
        await expect(page.locator('[data-export-pdf]')).toBeVisible();
    }

    // Filters: a period for balances, a number of days for dormant accounts.
    await page.goto('/accounting/controls/customer-balances');
    await expect(page.locator('[data-control-filters]')).toBeVisible();
    await page.locator('[data-control-apply]').click();
    await expect(page).toHaveURL(/start_date=/);

    await page.goto('/accounting/controls/dormant-accounts');
    await page.locator('[data-control-filters] input').fill('30');
    await page.locator('[data-control-apply]').click();
    await expect(page).toHaveURL(/days=30/);
    await expect(page.locator('[data-control-table] tbody tr').first()).toBeVisible();

    // The Excel link downloads a real workbook.
    const [download] = await Promise.all([page.waitForEvent('download'), page.locator('[data-export-xlsx]').click()]);
    expect(download.suggestedFilename()).toMatch(/dormant-accounts-\d+\.xlsx/);
    expect(errors).toEqual([]);
});
