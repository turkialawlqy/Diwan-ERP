import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

/* Split screen: a second page beside the current one, in a frame without its own menu and top bar. */

test('split two pages, no duplicate menu in the frame, back to one screen', async ({ page }) => {
    const errors = watchErrors(page);
    await page.setViewportSize({ width: 1500, height: 800 });
    await page.goto('/sales/invoices');
    await page.locator('[data-split-toggle]').click();
    await expect(page.locator('[data-split-pane]')).toBeVisible();

    // Choose the ledger for the second pane.
    await page.locator('[data-split-pick]').selectOption({ index: 1 });
    await expect(page.locator('[data-split-frame]')).toBeVisible();
    const frame = page.frameLocator('[data-split-frame]');
    await expect(frame.locator('main')).toBeVisible();
    // The embedded page has no side menu or top bar of its own.
    await expect(frame.locator('[data-sidebar]')).toHaveCount(0);
    await expect(frame.locator('header')).toHaveCount(0);

    // It survives a refresh, and closing returns to a single screen.
    await page.reload();
    await expect(page.locator('[data-split-pane]')).toBeVisible();
    await page.locator('[data-split-close]').click();
    await expect(page.locator('[data-split-pane]')).toHaveCount(0);
    expect(errors).toEqual([]);
});
