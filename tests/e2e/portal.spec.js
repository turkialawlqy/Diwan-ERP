import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { watchErrors, pickFromLookup } from './helpers.js';

/* Customer portal: staff share a contract and invite a customer; he signs in with the e-mailed code, opens a ticket and accepts the contract. */
const lastCode = () => {
    const log = readFileSync('storage/logs/laravel.log', 'utf8');
    const all = [...log.matchAll(/\*\*(\d{6})\*\*/g)];
    return all.at(-1)?.[1];
};

test('portal: invite, sign in with a code, open a ticket and accept a shared contract', async ({ page, browser, baseURL }) => {
    const errors = watchErrors(page);
    const email = `portal${Date.now()}@example.test`;

    // Staff: a contract for the customer, shared and requiring acceptance.
    await page.goto('/contracts/create', { waitUntil: 'networkidle' });
    await page.locator('[data-title]').fill('عقد البوابة');
    await pickFromLookup(page, page.getByPlaceholder('ابحث بالاسم').first(), 'ع');
    await page.locator('[data-body]').fill('نص العقد المعروض على العميل');
    await page.locator('[data-save-contract]').click();
    await page.locator('[data-submit]').click();
    await expect(page.locator('[data-status]')).toHaveText('ساري');
    await page.locator('[data-share-toggle]').check();
    await page.locator('[data-require-acceptance]').check();

    // Staff: invite a portal user for the same customer.
    await page.goto('/portal-users/create', { waitUntil: 'networkidle' });
    await pickFromLookup(page, page.getByPlaceholder('ابحث بالاسم').first(), 'ع');
    await page.locator('[data-portal-name]').fill('سارة');
    await page.locator('[data-portal-email-input]').fill(email);
    await page.locator('[data-save-portal-user]').click();
    await expect(page.locator(`[data-portal-user="${email}"]`)).toBeVisible();
    const portalUrl = await page.locator('[data-copy-portal]').getAttribute('data-portal-url');

    // Customer: a fresh browser with no staff session.
    const ctx = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    const c = await ctx.newPage();
    const cErrors = watchErrors(c);
    await c.goto(portalUrl, { waitUntil: 'networkidle' });
    await c.locator('[data-portal-email]').fill(email);
    await c.locator('[data-portal-send]').click();
    await expect(c.locator('[data-portal-code]')).toBeVisible();
    await expect.poll(lastCode).toMatch(/^\d{6}$/);
    await c.locator('[data-portal-code]').fill(lastCode());
    await c.locator('[data-portal-verify]').click();
    await expect(c.locator('[data-portal-home]')).toBeVisible();

    await c.locator('[data-portal-nav]').getByText('الفواتير').click();
    await expect(c.getByRole('heading', { name: 'الفواتير' })).toBeVisible();

    await c.locator('[data-portal-nav]').getByText('التذاكر').click();
    await c.locator('[data-new-ticket]').click();
    await c.locator('[data-subject]').fill('أحتاج مساعدة');
    await c.locator('[data-description]').fill('تفاصيل المشكلة');
    await c.locator('[data-save-ticket]').click();
    await expect(c.locator('[data-portal-flash]')).toContainText('وصلتنا تذكرتك');

    await c.locator('[data-portal-nav]').getByText('العقود').click();
    await c.locator('[data-contract]').filter({ hasText: 'عقد البوابة' }).getByRole('link').click();
    await c.locator('[data-accept-name]').fill('سارة محمد');
    await c.locator('[data-accept-agree]').check();
    await c.locator('[data-accept-submit]').click();
    await expect(c.locator('[data-accepted]')).toBeVisible();

    await c.locator('[data-portal-logout]').click();
    await expect(c.locator('[data-portal-email]')).toBeVisible();
    expect(cErrors).toEqual([]);
    await ctx.close();

    // Staff see the acceptance on the contract.
    await page.goto('/contracts', { waitUntil: 'networkidle' });
    await page.locator('[data-contract]').filter({ hasText: 'عقد البوابة' }).getByRole('link').first().click();
    await expect(page.locator('[data-acceptance]')).toContainText('سارة محمد');
    expect(errors).toEqual([]);
});
