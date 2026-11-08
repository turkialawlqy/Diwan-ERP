import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

/* Overhead allocation: list, form with preview, and no console errors. */
test('overhead allocation screens open and the form previews', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/projects/allocations');
    await expect(page.locator('main')).toBeVisible();
    await page.goto('/projects/allocations/create');
    await expect(page.locator('[data-allocation-form]')).toBeVisible();
    await expect(page.locator('[data-allocation-post]')).toBeDisabled();
    await page.locator('[data-allocation-preview]').isDisabled();
    expect(errors).toEqual([]);
});
