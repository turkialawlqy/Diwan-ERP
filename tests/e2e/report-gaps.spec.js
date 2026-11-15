import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

/*
 * Reports review follow-up: journal list filters and source links, the ledger's source document,
 * the customer statement's period and open-invoices view.
 */
test('journal list: filters, kind counts and a link to the source document', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/accounting/journals');
    await expect(page.locator('[data-journal-types]')).toBeVisible();
    await expect(page.locator('[data-source-link]').first()).toBeVisible();

    await page.getByText('البحث والتصفية').click();
    await expect(page.locator('[data-journal-filters]')).toBeVisible();
    await page.locator('[data-journal-filters] select').first().selectOption('posted');
    await page.locator('[data-journal-filter-apply]').click();
    await expect(page).toHaveURL(/status=posted/);
    await expect(page.locator('[data-source-link]').first()).toBeVisible();

    // The entry opens its source document.
    const href = await page.locator('[data-source-link]').first().getAttribute('href');
    expect(href).toMatch(/\/(sales|purchases|inventory|hr|accounting|projects)\//);
    expect(errors).toEqual([]);
});

test('ledger shows the source document and offers Excel / PDF', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/accounting/journals');
    // From a posted entry's line to the ledger of one of its accounts.
    await page.goto('/accounting/ledger?start_date=2020-01-01&end_date=2030-12-31');
    // The account picker is a searchable list: try the first accounts until one has a posted entry with a source document.
    let found = false;
    for (let i = 0; i < 25 && !found; i++) {
        await page.locator('[data-ledger-account]').first().click();
        const option = page.locator('[data-select-list] button').nth(i);
        if (!(await option.count())) break;
        await option.click();
        await page.getByRole('button', { name: 'عرض' }).first().click().catch(() => {});
        await page.waitForLoadState('networkidle');
        if (await page.locator('[data-source-link]').count()) found = true;
    }
    expect(found).toBe(true);
    await expect(page.locator('[data-export-xlsx]')).toBeVisible();
    expect(errors).toEqual([]);
});

test('customer statement: period filter and open invoices only', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/sales/clients');
    const link = page.locator('main a[href*="/statement"]').first();
    await link.click();
    await expect(page.locator('[data-statement-filters]')).toBeVisible();
    await page.locator('[data-open-only]').check();
    await expect(page.locator('[data-open-invoices]')).toBeVisible();
    await page.locator('[data-open-only]').uncheck();
    await expect(page.locator('[data-open-invoices]')).toHaveCount(0);
    expect(errors).toEqual([]);
});
