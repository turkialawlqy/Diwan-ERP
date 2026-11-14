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
    await page.goto('/settings');
    await expect(page.getByText('Company identity and official data').first()).toBeVisible();

    // Back to Arabic for the other specs.
    await header.locator('[data-user-menu]').click();
    await page.locator('[data-language-toggle]').click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await expect(page.getByText('الهوية والبيانات الرسمية للمنشأة').first()).toBeVisible();
    expect(errors).toEqual([]);
});

test('the quick add and view menus and the shared components follow the language', async ({ page }) => {
    const errors = watchErrors(page);
    await signIn(page);
    await page.goto('/sales/invoices');
    const header = page.locator('header');
    await header.locator('[data-user-menu]').click();
    await page.locator('[data-language-toggle]').click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');

    const arabic = /[؀-ۿ]/;
    await page.locator('[data-quick-add]').click();
    const add = page.locator('[data-quick-add-panel]');
    await expect(add.getByText('Sales invoice')).toBeVisible();
    expect(await add.innerText()).not.toMatch(arabic);
    await page.keyboard.press('Escape');

    await page.locator('[data-quick-view]').click();
    const view = page.locator('[data-quick-view-panel]');
    await expect(view).toBeVisible();
    expect(await view.innerText()).not.toMatch(arabic);

    // Back to Arabic for the other specs.
    await header.locator('[data-user-menu]').click();
    await page.locator('[data-language-toggle]').click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    await page.locator('[data-quick-add]').click();
    await expect(page.locator('[data-quick-add-panel]').getByText('فاتورة مبيعات')).toBeVisible();
    expect(errors).toEqual([]);
});

test('sales list and form pages follow the language', async ({ page }) => {
    const errors = watchErrors(page);
    await signIn(page);
    await page.goto('/sales/invoices');
    const header = page.locator('header');
    await header.locator('[data-user-menu]').click();
    await page.locator('[data-language-toggle]').click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'en');

    const arabic = /[؀-ۿ]/;
    for (const [url, text] of [
        ['/sales/invoices', 'Total invoices'],
        ['/sales/clients', 'Total customers'],
        ['/sales/payments', 'Total receipt vouchers'],
        ['/sales/recurring', 'Recurring invoices'],
        ['/sales/returns/create', 'Credit note details'],
        ['/sales/quotations/create', 'Quotation details'],
        ['/sales/orders', 'Sales orders'],
        ['/sales/quotations', 'Total quotations'],
        ['/sales/subscriptions', 'Customer subscriptions'],
        ['/sales/commissions', 'Sales commissions'],
        ['/sales/promotions', 'Promotions and discounts'],
        ['/sales/price-lists', 'Price lists'],
        ['/sales/customer-groups', 'Customer groups'],
        ['/sales/deliveries', 'Delivery notes'],
        ['/accounting/cheques', 'Cheque management'],
        ['/accounting/accounts', 'Chart of accounts'],
        ['/accounting/journals', 'Total journal entries'],
        ['/accounting/journals/create', 'Journal entry'],
        ['/accounting/mappings', 'Automatic account mapping'],
        ['/accounting/fixed-assets', 'Post monthly depreciation'],
        ['/accounting/period-end', 'Revaluation of foreign currency balances'],
        ['/accounting/fiscal-years', 'Fiscal years and periods'],
        ['/accounting/recurring', 'Recurring entries'],
        ['/accounting/reconciliations', 'Bank reconciliations'],
        ['/accounting/cost-centers', 'Cost centers'],
        ['/accounting/budgets', 'Budgets'],
        ['/accounting/reports/vat-return', 'VAT return'],
        ['/accounting/trial-balance', 'Trial balance'],
        ['/purchases/invoices', 'Total purchases'],
        ['/purchases/orders', 'Total purchase orders'],
        ['/purchases/suppliers', 'Total suppliers'],
        ['/purchases/payments', 'Supplier payment vouchers'],
        ['/purchases/landed-costs', 'Landed costs'],
        ['/purchases/receipts', 'Goods receipt'],
        ['/purchases/requests', 'Purchase requests'],
        ['/purchases/returns', 'Purchase returns'],
        ['/inventory/products', 'Total products'],
        ['/inventory/warehouses', 'Total warehouses'],
        ['/inventory/counts', 'Stock counting'],
        ['/inventory/stock-transfers', 'Stock transfers'],
        ['/inventory/operations', 'Stock movements'],
        ['/inventory/categories', 'Categories'],
        ['/inventory/assembly', 'Assembly and bills of materials (BOM)'],
        ['/pos/history', 'Go to the point of sale'],
        ['/pos/registers', 'Cashier devices'],
    ]) {
        await page.goto(url);
        await expect(page.getByText(text).first()).toBeVisible();
        // The breadcrumb follows the language too.
        expect(await page.locator('nav[aria-label], nav').first().innerText()).not.toMatch(arabic);
    }

    await header.locator('[data-user-menu]').click();
    await page.locator('[data-language-toggle]').click();
    await expect(page.locator('html')).toHaveAttribute('lang', 'ar');
    expect(errors).toEqual([]);
});
