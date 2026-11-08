import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

/* Report gaps, batch 3: aging with custom buckets, cash-flow scenarios, closing checklist, budget views. */
test('aging takes custom buckets and keeps them in the export link', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/accounting/reports/aging');
    await page.locator('input[placeholder="30,60,90"]').fill('10,20,40');
    await page.locator('form button').first().click();
    await expect(page).toHaveURL(/buckets=10(%2C|,)20(%2C|,)40/);
    await expect(page.locator('thead')).toContainText('21-40');
    await expect(page.locator('[data-export-xlsx]')).toHaveAttribute('href', /buckets=10/);
    expect(errors).toEqual([]);
});

test('cash forecast scenario recalculates and exports with the scenario', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/reports/insights');
    const form = page.locator('[data-cash-scenario]');
    await expect(form).toBeVisible();
    await form.locator('input').nth(1).fill('50');
    await form.locator('button').first().click();
    await expect(page).toHaveURL(/collect_pct=50/);
    await expect(page.locator('[data-cash-scenario] [data-export-xlsx]')).toHaveAttribute('href', /collect_pct=50/);
    expect(errors).toEqual([]);
});

test('closing checklist opens from the fiscal-year close screen', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/accounting/fiscal-years');
    const closeLink = page.locator('a[href*="/close"]').first();
    if (await closeLink.count()) {
        await closeLink.click();
        await page.locator('[data-closing-checklist-link]').click();
    } else {
        await page.goto('/accounting/controls/closing-checklist');
    }
    await expect(page.locator('[data-control-table]')).toBeVisible();
    await expect(page.locator('[data-control-table] tbody tr').first()).toBeVisible();
    expect(errors).toEqual([]);
});

test('budget dimension report and forecast snapshot button', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/accounting/controls/budget-dimensions');
    await expect(page.locator('[data-control-filters] select')).toBeVisible();
    await page.locator('[data-control-filters] select').selectOption('project');
    await page.locator('[data-control-apply]').click();
    await expect(page).toHaveURL(/dimension=project/);
    await page.goto('/accounting/controls/forecast-vs-actual');
    await page.locator('[data-forecast-snapshot]').click();
    await expect(page.locator('[data-control-table]')).toBeVisible();
    expect(errors).toEqual([]);
});
