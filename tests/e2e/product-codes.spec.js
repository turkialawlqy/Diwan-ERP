import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { watchErrors } from './helpers.js';

/*
 * The product code a form suggests comes from the numbering service («دليل الترميز») and a code kept
 * as suggested is reserved by the server, so two products never get the same one: not after
 * «حفظ وإضافة جديد», not on the next visit, and not from the purchase invoice's «صنف جديد» dialog
 * once the company has more products than the form lists.
 */
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], {
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: `${process.cwd()}/database/e2e.sqlite`, APP_ENV: 'local', CACHE_STORE: 'array', QUEUE_CONNECTION: 'sync' },
    encoding: 'utf8',
});
const skusOf = (names) => tinker(`echo App\\Models\\Product::withoutGlobalScopes()->whereIn('name_ar', ${JSON.stringify(names)})->orderBy('id')->pluck('sku')->implode('|');`)
    .trim().split('\n').pop().split('|');
const CODE = /^PRD-\d{5}$/;

test('product page: a code kept as suggested is not suggested again, also after «حفظ وإضافة جديد»', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/inventory/products/create');
    const name = page.getByPlaceholder('مثال: لابتوب ديل انسبيرون...');
    const sku = page.locator('label:has-text("رمز المنتج (SKU)") + input');

    const first = await sku.inputValue();
    expect(first).toMatch(CODE);
    await name.fill('صنف برمز مقترح 1');
    await page.locator('[data-save-and-new]').click();
    await expect(name).toHaveValue('');
    // Back on an empty form: the field shows the next code, not the one just saved.
    await expect(sku).not.toHaveValue(first);
    const second = await sku.inputValue();
    expect(second).toMatch(CODE);

    await name.fill('صنف برمز مقترح 2');
    await page.getByRole('button', { name: 'حفظ المنتج' }).first().click();
    await expect(page).not.toHaveURL(/\/create$/);

    await page.goto('/inventory/products/create');
    const third = await sku.inputValue();
    expect([first, second]).not.toContain(third);
    await name.fill('صنف برمز مقترح 3');
    await page.getByRole('button', { name: 'حفظ المنتج' }).first().click();
    await expect(page).not.toHaveURL(/\/create$/);

    expect(skusOf(['صنف برمز مقترح 1', 'صنف برمز مقترح 2', 'صنف برمز مقترح 3'])).toEqual([first, second, third]);
    expect(errors).toEqual([]);
});

test('purchase invoice: the «صنف جديد» dialog takes the numbering service code, which never repeats', async ({ page }) => {
    // More active products than the form lists (named to sort last), so the list length cannot give a free code.
    tinker(`$c = App\\Models\\Company::first(); $now = now();
        foreach (range(1, 210) as $i) {
            Illuminate\\Support\\Facades\\DB::table('products')->insert(['company_id' => $c->id, 'name_ar' => sprintf('ييصنف-%03d', $i), 'sku' => sprintf('E2E-CAP-%03d', $i),
                'type' => 'product', 'is_active' => true, 'selling_price' => 5, 'purchase_price' => 3, 'tax_rate' => 15, 'created_at' => $now, 'updated_at' => $now]);
        }
        echo 'ok';`);
    const errors = watchErrors(page);
    page.on('dialog', (dialog) => dialog.accept()); // leaving the unsaved invoice to open it again

    const fromDialog = async (productName) => {
        const line = page.getByPlaceholder('اختر أو اكتب اسم منتج للإنشاء...').first();
        await line.click();
        await line.fill(productName);
        await page.locator('[data-product-list]').getByText(`إنشاء "${productName}"`).click();
        const dialogName = page.getByPlaceholder('مثال: زيت محرك 5W-30');
        await expect(dialogName).toHaveValue(productName);
        const code = await page.locator('label:has-text("كود الصنف (SKU)") + input').inputValue();
        await page.getByRole('button', { name: 'حفظ وإدراج في الفاتورة' }).click();
        await expect(dialogName).toBeHidden();
        await expect(line).toHaveValue(productName);
        return code;
    };

    await page.goto('/purchases/invoices/create');
    const first = await fromDialog('صنف من فاتورة شراء 1');
    await page.goto('/purchases/invoices/create');
    const second = await fromDialog('صنف من فاتورة شراء 2');
    // A second product from the same page leaves its code to the server.
    const third = await fromDialog('صنف من فاتورة شراء 3');

    // A code another product holds is refused in the dialog with a message, not a server error.
    const line = page.getByPlaceholder('اختر أو اكتب اسم منتج للإنشاء...').first();
    await line.click();
    await line.fill('صنف برمز مكرر');
    await page.locator('[data-product-list]').getByText('إنشاء "صنف برمز مكرر"').click();
    await page.locator('label:has-text("كود الصنف (SKU)") + input').fill('E2E-CAP-001');
    await page.getByRole('button', { name: 'حفظ وإدراج في الفاتورة' }).click();
    await expect(page.getByText('رمز الصنف مستخدم لصنف آخر')).toBeVisible();
    await expect(page.getByPlaceholder('مثال: زيت محرك 5W-30')).toBeVisible();

    expect(first).toMatch(CODE);
    expect(second).toMatch(CODE);
    expect(second).not.toBe(first);
    expect(third).toBe('');
    const codes = skusOf(['صنف من فاتورة شراء 1', 'صنف من فاتورة شراء 2', 'صنف من فاتورة شراء 3']);
    expect(codes.slice(0, 2)).toEqual([first, second]);
    expect(codes[2]).toMatch(CODE);
    expect(new Set(codes).size).toBe(3);
    // The refused code answers 422, which the browser logs; anything else is a fault.
    expect(errors.filter((e) => !/status of 422/.test(e))).toEqual([]);
});
