import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { watchErrors, pickFromLookup } from './helpers.js';

/* Sector packs: apply a pack, use the gym check-in, add a vehicle, and rent a unit (the rent invoice appears). */
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], {
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: `${process.cwd()}/database/e2e.sqlite`, APP_ENV: 'local', CACHE_STORE: 'array' }, encoding: 'utf8',
}).trim().split('\n').pop();

test('sector packs: apply a pack and use its modules', async ({ page }) => {
    test.setTimeout(240_000);
    const errors = watchErrors(page);
    const suffix = String(Date.now()).slice(-5);

    // 1) The clinic and gym packs seed ready-made services.
    await page.goto('/settings/sector-packs', { waitUntil: 'networkidle' });
    for (const key of ['clinic', 'gym']) {
        page.once('dialog', (d) => d.accept());
        await page.locator(`[data-pack="${key}"] [data-apply]`).click();
        await expect(page.locator(`[data-pack="${key}"] [data-applied]`)).toBeVisible();
    }
    await page.goto('/bookings/services', { waitUntil: 'networkidle' });
    await expect(page.locator('[data-service="كشف"]')).toBeVisible();
    await expect(page.locator('[data-service="تدريب شخصي"]')).toBeVisible();

    // 2) Gym: a member with a live subscription is let in; an unknown code is not.
    tinker(`$c = App\\Models\\Company::first(); app(App\\Services\\TenantManager::class)->runAs($c, function () { $cust = App\\Models\\Customer::first(); $cust->forceFill(['code' => 'GYM-${suffix}'])->save(); $plan = App\\Models\\ServicePlan::where('name', 'عضوية شهرية')->first(); app(App\\Services\\Subscriptions\\SubscriptionService::class)->start(['customer_id' => $cust->id, 'service_plan_id' => $plan->id, 'quantity' => 1, 'start_date' => now()->toDateString()]); }); echo 'ok';`);
    await page.goto('/gym/checkin', { waitUntil: 'networkidle' });
    await page.locator('[data-checkin-code]').fill(`GYM-${suffix}`);
    await page.locator('[data-checkin-code]').press('Enter');
    await expect(page.locator('[data-verdict]')).toContainText('مسموح بالدخول');
    await page.locator('[data-checkin-code]').fill('NOPE-1');
    await page.locator('[data-checkin-code]').press('Enter');
    await expect(page.locator('[data-verdict]')).toHaveText('غير مسموح');
    await expect(page.locator('[data-reason]')).toContainText('الكود غير معروف');

    // 3) Workshop: a customer's vehicle.
    await page.goto('/workshop/vehicles/create', { waitUntil: 'networkidle' });
    await pickFromLookup(page, page.getByPlaceholder('ابحث بالاسم').first(), 'ع');
    await page.locator('[data-plate]').fill(`VEH ${suffix}`);
    await page.locator('[data-save-vehicle]').click();
    await expect(page.getByRole('heading', { name: new RegExp(`VEH ${suffix}`) })).toBeVisible();

    // 4) Real estate: property, unit, lease; the first rent invoice is issued at once.
    await page.goto('/real-estate/properties/create', { waitUntil: 'networkidle' });
    await page.locator('[data-property-name]').fill(`عمارة ${suffix}`);
    await page.locator('[data-save-property]').click();
    await page.locator('[data-new-unit]').click();
    await page.locator('[data-unit-number]').fill('A1');
    await page.locator('[data-unit-rent]').fill('2500');
    await page.locator('[data-save-unit]').click();
    await page.goto('/real-estate/properties', { waitUntil: 'networkidle' });
    await page.locator(`[data-property="عمارة ${suffix}"] a`).first().click();
    await page.locator('[data-rent-unit]').click();
    await pickFromLookup(page, page.getByPlaceholder('ابحث بالاسم').first(), 'ع');
    const end = new Date(); end.setFullYear(end.getFullYear() + 1);
    await page.locator('[data-date-picker] input').nth(1).fill(end.toISOString().slice(0, 10));
    await page.locator('[data-date-picker] input').nth(1).press('Escape');
    await page.locator('[data-save-lease]').click();
    await expect(page.locator('[data-status]')).toHaveText('ساري');
    await expect(page.locator('[data-rent-invoice]')).toHaveCount(1);
    expect(errors).toEqual([]);
});
