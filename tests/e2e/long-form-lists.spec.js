import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { watchErrors } from './helpers.js';

/*
 * The sales and payment request forms send only their first records: what lies beyond them
 * is found by typing, can be picked, and fills the form as a listed record does.
 * Each "-99" record is the last of 130 in name order, so it is never in the first hundred.
 */
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], {
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: `${process.cwd()}/database/e2e.sqlite`, APP_ENV: 'local', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync' },
    encoding: 'utf8',
});
const DB = 'Illuminate\\Support\\Facades\\DB';

test.beforeAll(() => {
    tinker(`$c = App\\Models\\Company::first(); $t = ['company_id' => $c->id, 'created_at' => now(), 'updated_at' => now()];
        foreach (range(1, 130) as $i) {
            ${DB}::table('customers')->insert($t + ['name' => 'عميلبعيد-'.$i, 'phone' => '05990'.$i, 'customer_type' => 'commercial', 'status' => 'active', 'current_balance' => 123]);
            ${DB}::table('suppliers')->insert($t + ['name' => 'موردبعيد-'.$i, 'status' => 'active']);
            ${DB}::table('products')->insert($t + ['name_ar' => 'منتجبعيد-'.$i, 'sku' => 'FAR-'.$i, 'type' => 'product', 'is_active' => true, 'selling_price' => 77, 'tax_rate' => 15]);
        }
        $far = ${DB}::table('suppliers')->where('company_id', $c->id)->where('name', 'موردبعيد-99')->value('id');
        $near = ${DB}::table('suppliers')->where('company_id', $c->id)->where('name', 'موردبعيد-1')->value('id');
        foreach ([[$far, 'E2E-FAR-OPEN', 321], [$far, 'E2E-FAR-PAID', 0], [$near, 'E2E-NEAR-OPEN', 55]] as [$supplier, $number, $left]) {
            ${DB}::table('purchase_invoices')->insert($t + ['supplier_id' => $supplier, 'invoice_number' => $number, 'invoice_date' => '2026-01-10', 'subtotal' => 100, 'total' => 100, 'remaining_amount' => $left]);
        }
        echo 'ok';`);
});

const field = (scope, index = 0) => scope.locator('[data-select-create]').nth(index).locator('input[type=text]').first();
const list = (page) => page.locator('[data-select-list]');

/** Types into a SelectCreate a record that is not listed before typing, and picks it. */
async function pickBeyondTheFirst(page, input, text) {
    await input.click();
    await expect(list(page).locator('button').first()).toBeVisible();
    await expect(list(page).locator('button').filter({ hasText: text })).toHaveCount(0);
    await input.pressSequentially(text, { delay: 20 });
    const found = list(page).locator('button:not([data-create-option])').filter({ hasText: text }).first();
    await expect(found).toBeVisible();
    await found.click();
    await expect(input).toHaveValue(new RegExp(text));
}

test('quotation: a customer and a product beyond the first records are found, picked and fill the form', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/sales/quotations/create');

    await pickBeyondTheFirst(page, field(page), 'عميلبعيد-99');
    await expect(page.locator('main')).toContainText('0599099'); // the customer's details under the field

    const line = page.locator('table tbody tr').first();
    await pickBeyondTheFirst(page, field(line), 'منتجبعيد-99');
    await expect(page.getByPlaceholder('اسم الصنف أو الخدمة *').first()).toHaveValue('منتجبعيد-99');
    await expect(line.locator('input[data-num]').nth(1)).toHaveValue('77');

    await page.getByRole('button', { name: 'حفظ عرض السعر' }).last().click();
    await expect(page).toHaveURL(/\/sales\/quotations\/\d+$/);
    await expect(page.locator('body')).toContainText('عميلبعيد-99');
    await expect(page.locator('body')).toContainText('منتجبعيد-99');
    expect(errors).toEqual([]);
});

test('sales invoice: its own pickers still find what is beyond the first records', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/sales/invoices/create');

    const customer = page.getByPlaceholder('ابحث برقم العميل، الاسم، الجوال أو اكتب اسماً جديداً...');
    await customer.click();
    await customer.fill('');
    await customer.pressSequentially('عميلبعيد-99', { delay: 30 });
    await page.locator('.py-1 > div.cursor-pointer').filter({ hasText: 'عميلبعيد-99' }).first().click();
    await expect(customer).toHaveValue('عميلبعيد-99');

    const product = page.getByPlaceholder('اختر أو انشئ منتج...').first();
    await product.click();
    await product.fill('');
    await product.pressSequentially('منتجبعيد-99', { delay: 30 });
    await page.locator('.py-1 > div.cursor-pointer').filter({ hasText: 'منتجبعيد-99' }).first().click();
    await expect(product).toHaveValue('منتجبعيد-99');
    expect(errors).toEqual([]);
});

test('sales return: a customer found by typing shows the balance as a listed one does', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/sales/returns/create');

    await pickBeyondTheFirst(page, field(page), 'عميلبعيد-99');
    await expect(page.locator('main')).toContainText('الرصيد الحالي للعميل');
    await expect(page.locator('main')).toContainText('123.00');
    expect(errors).toEqual([]);
});

test('promotion: a product beyond the first records is found and saved', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/sales/promotions/create');

    const form = page.locator('form');
    await form.locator('input').first().fill('عرض لصنف بعيد');
    await form.locator('input[data-num]').nth(1).fill('10');
    await pickBeyondTheFirst(page, field(form), 'منتجبعيد-99');

    await form.locator('button[type=submit]').click();
    await expect(page).toHaveURL(/\/sales\/promotions$/);
    await expect(page.locator('main')).toContainText('منتجبعيد-99');
    expect(errors).toEqual([]);
});

test('payment request: a chosen supplier lists only their open invoices, and the choice is kept and saved', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/finance/payment-requests/create');

    const form = page.locator('[data-payment-request-form]');
    await form.locator('select').first().selectOption('supplier_payment');
    const supplier = field(form, 0);
    const invoice = field(form, 1);

    await pickBeyondTheFirst(page, supplier, 'موردبعيد-99');

    await invoice.click();
    await expect(list(page).getByText('E2E-FAR-OPEN')).toBeVisible();
    await expect(list(page).getByText('E2E-FAR-PAID')).toHaveCount(0);
    await expect(list(page).getByText('E2E-NEAR-OPEN')).toHaveCount(0);
    await list(page).getByText('E2E-FAR-OPEN').click();
    await expect(invoice).toHaveValue(/E2E-FAR-OPEN/);

    // Another supplier's invoice is not offered while this one is chosen, even when typed.
    await invoice.click();
    await invoice.pressSequentially('E2E-NEAR', { delay: 20 });
    await expect(list(page).getByText('لا توجد نتائج')).toBeVisible();
    await page.keyboard.press('Escape');

    // With no supplier chosen the list is everyone's again and still names the chosen invoice.
    await supplier.click();
    await list(page).locator('button').first().click();
    await expect(invoice).toHaveValue(/E2E-FAR-OPEN/);
    await invoice.click();
    await invoice.pressSequentially('E2E-NEAR', { delay: 20 });
    await expect(list(page).getByText('E2E-NEAR-OPEN')).toBeVisible();
    await page.keyboard.press('Escape');

    await supplier.click();
    await supplier.pressSequentially('موردبعيد-99', { delay: 20 });
    await list(page).locator('button:not([data-create-option])').filter({ hasText: 'موردبعيد-99' }).first().click();
    await expect(invoice).toHaveValue(/E2E-FAR-OPEN/);

    await form.locator('input[data-num]').first().fill('321');
    await form.locator('input:not([data-num]):not([type=hidden])').last().fill('دفعة لفاتورة بعيدة');
    await page.locator('[data-prq-save]').click();
    await expect(page).toHaveURL(/\/finance\/payment-requests\/\d+$/);
    await expect(page.locator('main')).toContainText('موردبعيد-99');

    const saved = tinker(`$r = App\\Models\\PaymentRequest::withoutGlobalScopes()->latest('id')->first(); echo ${DB}::table('purchase_invoices')->where('id', $r->purchase_invoice_id)->value('invoice_number');`);
    expect(saved).toContain('E2E-FAR-OPEN');
    expect(errors).toEqual([]);
});
