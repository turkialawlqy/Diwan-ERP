import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { watchErrors } from './helpers.js';

/*
 * An employee whose only branch was deleted has no branch left, so the server sends the
 * document forms an empty branch list. They still open, and new lines take the company's rate.
 */
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], {
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: `${process.cwd()}/database/e2e.sqlite`, APP_ENV: 'local', CACHE_STORE: 'array' }, encoding: 'utf8',
}).trim().split('\n').pop();

test('no branch left: quotation and purchase invoice forms open, new lines use the company rate', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = watchErrors(page);
    const sfx = String(Date.now()).slice(-6);
    const branch = `فرع مؤقت ${sfx}`;
    const email = `no-branch-${sfx}@diwan.io`;
    tinker(`$c = App\\Models\\Company::first(); app(App\\Services\\TenantManager::class)->runAs($c, function () use ($c) { $b = App\\Models\\Branch::create(['name_ar' => '${branch}', 'code' => 'TMP${sfx}', 'is_active' => true]); $u = (new App\\Models\\User)->forceFill(['company_id' => $c->id, 'name' => 'موظف ${sfx}', 'email' => '${email}', 'password' => Illuminate\\Support\\Facades\\Hash::make('password'), 'branch_id' => $b->id, 'status' => 'active', 'is_super_admin' => false, 'email_verified_at' => now()]); $u->save(); $u->assignRole('sales', 'purchasing'); $u->branches()->sync([$b->id]); }); echo 'ok';`);

    // The owner deletes the employee's only branch from the branches screen.
    await page.goto('/branches', { waitUntil: 'networkidle' });
    await page.locator('[data-branches] > *', { hasText: branch }).getByTitle('حذف').click();
    await page.locator('[data-prompt-dialog] button[type=submit]').click();
    await expect(page.locator('[data-branches]')).not.toContainText(branch);

    await page.context().clearCookies();
    await page.goto('/login');
    await page.locator('input[type=email]').fill(email);
    await page.locator('input[type=password]').fill('password');
    await page.locator('button[type=submit]').click();
    await expect(page).not.toHaveURL(/\/login/);

    await page.goto('/sales/quotations/create', { waitUntil: 'networkidle' });
    expect(errors).toEqual([]);
    const quotationLines = page.locator('table tbody tr');
    await expect(quotationLines).toHaveCount(1);
    await expect(quotationLines.first().locator('td').nth(5).locator('input')).toHaveValue('15');
    await page.getByRole('button', { name: 'إضافة بند جديد' }).click();
    await expect(quotationLines).toHaveCount(2);
    await expect(quotationLines.nth(1).locator('td').nth(5).locator('input')).toHaveValue('15');
    await quotationLines.nth(1).locator('td').nth(3).locator('input').fill('100');
    await expect(quotationLines.nth(1).locator('td').nth(6)).toHaveText('115.00');

    await page.goto('/purchases/invoices/create', { waitUntil: 'networkidle' });
    expect(errors).toEqual([]);
    const purchaseLines = page.locator('table tbody tr');
    await expect(purchaseLines).toHaveCount(1);
    await expect(purchaseLines.first().locator('td').nth(4)).toHaveText('15%');
    await page.getByRole('button', { name: 'إضافة سطر' }).click();
    await expect(purchaseLines).toHaveCount(2);
    await expect(purchaseLines.nth(1).locator('td').nth(4)).toHaveText('15%');
    await purchaseLines.nth(1).locator('td').nth(3).locator('input').fill('100');
    await expect(purchaseLines.nth(1).locator('td').nth(5)).toHaveText('115.00');

    expect(errors).toEqual([]);
});
