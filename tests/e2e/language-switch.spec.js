import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

/*
 * Arabic ⇄ English without a reload: direction flips, and the parts of the interface that are
 * translated (top bar, work context, row actions, status badges) follow. Page bodies are being
 * translated gradually (see LocaleCoverageTest), so only shared chrome is asserted here.
 */
test.use({ storageState: { cookies: [], origins: [] } });

async function signIn(page) {
    await page.goto('/login');
    await page.locator('input[type=email]').fill('anas@diwan.io');
    await page.locator('input[type=password]').fill('password');
    await page.locator('button[type=submit]').click();
    await expect(page).not.toHaveURL(/\/login/);
}

test('switching to English flips the direction and translates the shared chrome, and back again', async ({ page }) => {
    const errors = watchErrors(page);
    await signIn(page);
    await page.goto('/sales/invoices');
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');

    const header = page.locator('header');
    await header.locator('[data-user-menu]').click();
    await page.locator('[data-language-toggle]').click();
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');

    await header.locator('[data-user-menu]').click();
    await expect(page.getByText('Sign out')).toBeVisible();
    await expect(page.locator('[data-language-toggle]')).toHaveText(/العربية/);
    await page.keyboard.press('Escape');
    await expect(page.locator('[data-context-card]')).toHaveAttribute('aria-label', /Work context/);
    await expect(header.getByTitle('Notifications')).toBeVisible();

    // The choice is saved: a reload stays English.
    await page.reload();
    await expect(page.locator('html')).toHaveAttribute('dir', 'ltr');

    await header.locator('[data-user-menu]').click();
    await page.locator('[data-language-toggle]').click();
    await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
    await header.locator('[data-user-menu]').click();
    await expect(page.getByText('تسجيل الخروج')).toBeVisible();
    expect(errors).toEqual([]);
});

test('converted page bodies follow the language: dashboard, insights and the invoice form', async ({ page }) => {
    const errors = watchErrors(page);
    await signIn(page);
    await page.goto('/dashboard');
    const header = page.locator('header');
    await header.locator('[data-user-menu]').click();
    await page.locator('[data-language-toggle]').click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');

    await expect(page.getByText('Quick actions').first()).toBeVisible();
    await page.goto('/reports/insights');
    await expect(page.getByText('Expected cash flow').first()).toBeVisible();
    await page.goto('/sales/invoices/create');
    await expect(page.getByText('Invoice basics').first()).toBeVisible();
    await expect(page.getByText('Add item').first()).toBeVisible();
    await page.goto('/accounting/fixed-assets');
    await expect(page.getByText('Post monthly depreciation').first()).toBeVisible();
    await page.goto('/hr/settings');
    await expect(page.getByText('Social insurance').first()).toBeVisible();
    await page.goto('/zatca');
    await expect(page.getByText('Invoicing devices (EGS)').first()).toBeVisible();

    // Back to Arabic for the other specs.
    await header.locator('[data-user-menu]').click();
    await page.locator('[data-language-toggle]').click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.getByText('أجهزة الفوترة (EGS)').first()).toBeVisible();
    expect(errors).toEqual([]);
});
