import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { watchErrors } from './helpers.js';

const tinker = (code) => execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], {
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: `${process.cwd()}/database/e2e.sqlite`, APP_ENV: 'local', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync' },
    encoding: 'utf8',
});

test('a product beyond the first records is found by typing, and picked', async ({ page }) => {
    const errors = watchErrors(page);
    tinker(`$c = App\\Models\\Company::first(); $now = now(); foreach (range(1, 130) as $i) { Illuminate\\Support\\Facades\\DB::table('products')->insert(['company_id' => $c->id, 'name_ar' => 'صنفطويل-'.$i, 'sku' => 'LONG-'.$i, 'type' => 'product', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]); } echo 'ok';`);

    await page.goto('/inventory/operations/transfer');
    const product = page.locator('[data-select-create]').first().locator('input[type=text]').first();
    await product.click();
    await product.pressSequentially('صنفطويل-128', { delay: 20 });

    const found = page.locator('[data-select-list] button:not([data-create-option])').filter({ hasText: 'صنفطويل-128' }).first();
    await expect(found).toBeVisible();
    await found.click();
    await expect(product).toHaveValue(/صنفطويل-128/);
    expect(errors).toEqual([]);
});

test('the payment voucher lists only the chosen supplier\'s open invoices', async ({ page }) => {
    const errors = watchErrors(page);
    tinker(`$c = App\\Models\\Company::first(); $now = now(); $s = App\\Models\\Supplier::withoutGlobalScopes()->where('company_id', $c->id)->where('status', 'active')->orderBy('name')->first(); foreach ([[$s->id, 'E2E-P1', 100], [App\\Models\\Supplier::withoutGlobalScopes()->where('company_id', $c->id)->where('status', 'active')->whereKeyNot($s->id)->first()->id, 'E2E-OTHER', 100], [$s->id, 'E2E-PAID', 0]] as [$sup, $no, $left]) { Illuminate\\Support\\Facades\\DB::table('purchase_invoices')->insert(['company_id' => $c->id, 'supplier_id' => $sup, 'invoice_number' => $no, 'invoice_date' => '2026-01-10', 'subtotal' => 100, 'total' => 100, 'remaining_amount' => $left, 'created_at' => $now, 'updated_at' => $now]); } echo 'ok';`);

    await page.goto('/purchases/payments/create');
    await expect(page.locator('form select').first().locator('option').filter({ hasText: 'E2E-P1' })).toHaveCount(1);
    await expect(page.locator('form').getByText('E2E-OTHER')).toHaveCount(0);
    await expect(page.locator('form').getByText('E2E-PAID')).toHaveCount(0);
    expect(errors).toEqual([]);
});
