import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { watchErrors, pickFromLookup } from './helpers.js';

/*
 * Work orders: the office creates an order and assigns a technician, the technician (on a phone) starts the
 * timer, ticks a task, takes the customer's signature and finishes, then the office bills it.
 */
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], {
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: `${process.cwd()}/database/e2e.sqlite`, APP_ENV: 'local', CACHE_STORE: 'array' }, encoding: 'utf8',
}).trim().split('\n').pop();

const pickOption = async (page, input, term) => {
    await input.click();
    await input.pressSequentially(term, { delay: 30 });
    await page.locator('[data-select-list] button:not([data-create-option])').filter({ hasText: term }).first().click();
};

test('work orders: office assigns, technician finishes on the phone, office invoices', async ({ page, browser }) => {
    test.setTimeout(180_000); // a long journey across two devices
    const errors = watchErrors(page);
    const suffix = String(Date.now()).slice(-5);
    const typeName = `صيانة ${suffix}`;
    const title = `إصلاح ${suffix}`;

    // The demo owner is also a technician: link his user to an employee.
    const techName = tinker("$u = App\\Models\\User::where('email','anas@diwan.io')->first(); $e = App\\Models\\Employee::withoutGlobalScopes()->where('company_id',$u->company_id)->first(); $e->forceFill(['user_id'=>$u->id])->save(); echo $e->name;");
    expect(techName.length).toBeGreaterThan(0);

    await page.goto('/work-orders/types/create', { waitUntil: 'networkidle' });
    await page.locator('[data-type-name]').fill(typeName);
    await page.locator('[data-rate]').fill('120');
    await page.locator('[data-save-type]').click();
    await expect(page.locator(`[data-type="${typeName}"]`)).toBeVisible();

    await page.goto('/work-orders/create', { waitUntil: 'networkidle' });
    await pickFromLookup(page, page.getByPlaceholder('ابحث بالاسم').first(), 'ع');
    await page.locator('[data-title]').fill(title);
    await pickOption(page, page.locator('[data-select-create] input').first(), typeName);
    await page.locator('[data-lead]').selectOption({ label: techName });
    await page.locator('[data-date-picker] input').first().fill(new Date(Date.now() + 86400000).toISOString().slice(0, 10));
    await page.locator('[data-date-picker] input').first().press('Escape');
    await page.locator('[data-add-task]').click();
    await page.locator('[data-task-input="0"]').fill('فحص الجهاز');
    await page.locator('[data-save-order]').click();
    await expect(page.locator('[data-status]')).toHaveText('مجدول');
    const orderUrl = page.url();

    // The technician's phone.
    const phone = await browser.newContext({ viewport: { width: 390, height: 844 }, storageState: 'tests/e2e/.auth/user.json', baseURL: orderUrl.split('/work-orders')[0] });
    const m = await phone.newPage();
    const mErrors = watchErrors(m);
    await m.goto('/work-orders/mine', { waitUntil: 'networkidle' });
    await m.locator('[data-order]').filter({ hasText: title }).locator('[data-open-order]').click();
    await m.locator('[data-start]').click();
    await expect(m.locator('[data-elapsed]')).toBeVisible();
    await m.locator('[data-task="فحص الجهاز"]').click();
    await expect(m.locator('[data-task="فحص الجهاز"] span').first()).toHaveText('✓');

    await m.locator('[data-sign-name]').fill('مدير الموقع');
    const box = await m.locator('[data-signature-canvas]').boundingBox();
    await m.mouse.move(box.x + 20, box.y + 40);
    await m.mouse.down();
    await m.mouse.move(box.x + 120, box.y + 90, { steps: 8 });
    await m.mouse.move(box.x + 200, box.y + 50, { steps: 8 });
    await m.mouse.up();
    await m.locator('[data-save-signature]').click();
    await expect(m.locator('[data-signed]')).toBeVisible();

    await m.locator('[data-stop]').click();
    await expect(m.locator('[data-start]')).toBeVisible();
    await m.locator('[data-notes]').fill('تم الإصلاح');
    m.once('dialog', (d) => d.accept());
    await m.locator('[data-complete]').click();
    await expect(m.locator('[data-mobile-flash]')).toContainText('تم إنجاز الأمر');
    expect(mErrors).toEqual([]);
    await phone.close();

    // The office adds billable time (the timer ran only seconds) and invoices.
    await page.goto(orderUrl, { waitUntil: 'networkidle' });
    await expect(page.locator('[data-status]')).toHaveText('منجز');
    // time can only be added while the order is open: reopen first
    await page.getByRole('button', { name: 'إعادة فتح' }).click();
    await expect(page.locator('[data-status]')).toHaveText('قيد التنفيذ');
    await page.locator('[data-time-employee]').selectOption({ label: techName });
    await page.locator('[data-time-minutes]').fill('90');
    await page.locator('[data-add-time]').click();
    await page.locator('[data-complete]').click();
    await page.locator('[data-confirm-complete]').click();
    await expect(page.locator('[data-status]')).toHaveText('منجز');
    await expect(page.locator('[data-revenue]')).toContainText('180');
    await page.locator('[data-invoice-btn]').click();
    await expect(page.locator('[data-status]')).toHaveText('مُفوتَر');
    await expect(page.locator('[data-invoice]')).toBeVisible();
    expect(errors).toEqual([]);
});
