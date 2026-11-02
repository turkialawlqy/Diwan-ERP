import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { watchErrors, pickFromLookup } from './helpers.js';

const lastCode = () => [...readFileSync('storage/logs/laravel.log', 'utf8').matchAll(/\*\*(\d{6})\*\*/g)].at(-1)?.[1];

/* Bookings: a service and a resource with weekly hours, a booking from the form, completing it (an invoice), and the calendar. */
const pickOption = async (page, input, term) => {
    await input.click();
    await input.pressSequentially(term, { delay: 30 });
    await page.locator('[data-select-list] button:not([data-create-option])').filter({ hasText: term }).first().click();
};

test('bookings: service, resource with hours, a booking, completion and the calendar', async ({ page }) => {
    const errors = watchErrors(page);
    const suffix = String(Date.now()).slice(-5);
    const service = `جلسة ${suffix}`;

    await page.goto('/bookings/services/create', { waitUntil: 'networkidle' });
    await page.locator('[data-service-name]').fill(service);
    await page.locator('[data-duration]').fill('60');
    await page.locator('[data-price]').fill('100');
    await page.locator('[data-save-service]').click();
    await expect(page.locator(`[data-service="${service}"]`)).toBeVisible();

    await page.goto('/bookings/resources/create', { waitUntil: 'networkidle' });
    await page.locator('[data-resource-name]').fill(`غرفة ${suffix}`);
    await page.locator('[data-fill-week]').click();
    await page.locator('label').filter({ hasText: service }).locator('input').check();
    await page.locator('[data-save-resource]').click();
    await expect(page.locator(`[data-resource="غرفة ${suffix}"]`)).toBeVisible();

    // A day that is not Friday (the demo week is Saturday to Thursday).
    const d = new Date();
    d.setDate(d.getDate() + 2);
    if (d.getDay() === 5) d.setDate(d.getDate() + 1);
    const day = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

    await page.goto('/bookings/create', { waitUntil: 'networkidle' });
    await pickFromLookup(page, page.getByPlaceholder('ابحث بالاسم').first(), 'ع');
    await pickOption(page, page.locator('[data-select-create] input').first(), service);
    await page.locator('[data-date-picker] input').first().fill(day);
    await page.locator('[data-slot="10:00"]').click();
    await page.locator('[data-save-booking]').click();

    await expect(page.locator('[data-status]')).toHaveText('مؤكد');
    await page.locator('[data-complete]').click();
    await page.locator('[data-confirm-complete]').click();
    await expect(page.locator('[data-status]')).toHaveText('منجز');
    await expect(page.locator('[data-invoice]')).toBeVisible();

    // The taken slot is gone for the next booking.
    await page.goto('/bookings/create', { waitUntil: 'networkidle' });
    await pickOption(page, page.locator('[data-select-create] input').first(), service);
    await page.locator('[data-date-picker] input').first().fill(day);
    await expect(page.locator('[data-slot="11:00"]')).toBeVisible();
    await expect(page.locator('[data-slot="10:00"]')).toHaveCount(0);

    await page.goto(`/bookings/calendar?date=${day}`, { waitUntil: 'networkidle' });
    await expect(page.locator('[data-calendar]')).toBeVisible();
    await expect(page.locator('[data-event]')).toHaveCount(1);
    expect(errors).toEqual([]);
});

test('bookings: the customer books from the portal and cancels', async ({ page, browser, baseURL }) => {
    const errors = watchErrors(page);
    const suffix = String(Date.now()).slice(-5);
    const service = `خدمة بوابة ${suffix}`;
    const email = `book${suffix}@example.test`;

    await page.goto('/bookings/services/create', { waitUntil: 'networkidle' });
    await page.locator('[data-service-name]').fill(service);
    await page.locator('[data-price]').fill('50');
    await page.locator('[data-save-service]').click();
    await expect(page.locator(`[data-service="${service}"]`)).toBeVisible();
    await page.goto('/bookings/resources/create', { waitUntil: 'networkidle' });
    await page.locator('[data-resource-name]').fill(`مورد ${suffix}`);
    await page.locator('[data-fill-week]').click();
    await page.locator('label').filter({ hasText: service }).locator('input').check();
    await page.locator('[data-save-resource]').click();
    await expect(page.locator(`[data-resource="مورد ${suffix}"]`)).toBeVisible();

    await page.goto('/portal-users/create', { waitUntil: 'networkidle' });
    await pickFromLookup(page, page.getByPlaceholder('ابحث بالاسم').first(), 'ع');
    await page.locator('[data-portal-name]').fill('سارة');
    await page.locator('[data-portal-email-input]').fill(email);
    await page.locator('[data-save-portal-user]').click();
    const portalUrl = await page.locator('[data-copy-portal]').getAttribute('data-portal-url');

    const ctx = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    const c = await ctx.newPage();
    const cErrors = watchErrors(c);
    await c.goto(portalUrl, { waitUntil: 'networkidle' });
    const before = lastCode();
    await c.locator('[data-portal-email]').fill(email);
    await c.locator('[data-portal-send]').click();
    await expect.poll(() => lastCode() !== before && lastCode()).toMatch(/^\d{6}$/);
    await c.locator('[data-portal-code]').fill(lastCode());
    await c.locator('[data-portal-verify]').click();
    await expect(c.locator('[data-portal-home]')).toBeVisible();

    // Four days ahead: past the minimum notice, and not a Friday.
    const d = new Date();
    d.setDate(d.getDate() + 4);
    if (d.getDay() === 5) d.setDate(d.getDate() + 1);
    const day = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;

    await c.locator('[data-portal-nav]').getByText('حجوزاتي').click();
    await c.locator('[data-new-booking]').click();
    await c.locator(`[data-service="${service}"]`).click();
    await c.locator('[data-date-picker] input').first().fill(day);
    await c.locator('[data-date-picker] input').first().press('Enter');
    await c.locator('[data-slot="10:00"]').click();
    await c.locator('[data-confirm-booking]').click();
    await expect(c.locator('[data-status]')).toHaveText('مؤكد');

    await c.locator('[data-cancel-booking]').click({ trial: true });
    c.once('dialog', (dlg) => dlg.accept());
    await c.locator('[data-cancel-booking]').click();
    await expect(c.locator('[data-status]')).toHaveText('ملغى');
    expect(cErrors).toEqual([]);
    await ctx.close();
    expect(errors).toEqual([]);
});
