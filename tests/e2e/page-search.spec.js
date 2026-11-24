import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { watchErrors } from './helpers.js';

/*
 * Global search: tab 1 «السجلات» (the default, as before) and tab 2 «الصفحات» (resources/js/pageIndex.js +
 * pageSearch.js): sections and pages the user may open, found by name with Arabic-aware matching, opened with the
 * keyboard; the records, the «إضافة» and «عرض» menus and Ctrl/⌘+K keep working as before. The engine has its own
 * unit tests (npm run test:js) and the server side is PageSearchIndexTest; this spec drives the real screen.
 */
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], {
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: `${process.cwd()}/database/e2e.sqlite`, APP_ENV: 'local', CACHE_STORE: 'array' }, encoding: 'utf8',
}).trim().split('\n').pop();
const setDisabled = (json) => tinker(`$c = App\\Models\\Company::first(); $c->setSetting('disabled_modules', ${json}, 'general', 'json'); echo 'ok';`);
// Status of a direct visit to a URL with the page's session (from the browser, which resolves *.localhost).
const statusOf = (page, url) => page.evaluate(async (u) => (await fetch(u, { headers: { Accept: 'text/html' } })).status, url);

const palette = (page) => page.locator('[data-search-palette]');
const input = (page) => palette(page).getByRole('combobox');
const rows = (page) => page.locator('#gsearch-pages [role=treeitem]');
// Every opening starts on «السجلات» (the first tab and the default).
const openWithKeyboard = async (page) => {
    await page.evaluate(() => document.activeElement?.blur());
    await page.keyboard.press('Control+k');
    await expect(palette(page)).toBeVisible();
    await expect(palette(page).locator('[data-search-tab=records]')).toHaveAttribute('aria-selected', 'true');
};
// Open, then the second tab, «الصفحات».
const openPages = async (page) => {
    await openWithKeyboard(page);
    await palette(page).locator('[data-search-tab=pages]').click();
    await expect(palette(page).locator('[data-search-tab=pages]')).toHaveAttribute('aria-selected', 'true');
    await expect(input(page)).toBeFocused();
};
const searchPages = async (page, text) => {
    await input(page).fill(text);
    await expect(palette(page).locator('[data-search-tab=pages]')).toHaveAttribute('aria-selected', 'true');
};

test('pages: a page by name, a section with its pages, keyboard to open it', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    await expect(page.locator('[data-search-open]')).toContainText('ابحث عن صفحة أو سجل');

    // Tab 1 «السجلات» (the default), tab 2 «الصفحات».
    await openWithKeyboard(page);
    await expect(palette(page).getByRole('tab')).toHaveCount(2);
    await expect(palette(page).getByRole('tab').nth(0)).toHaveAttribute('data-search-tab', 'records');
    await expect(palette(page).getByRole('tab').nth(1)).toHaveAttribute('data-search-tab', 'pages');
    await expect(input(page)).toBeFocused();
    await palette(page).locator('[data-search-tab=pages]').click();
    await expect(palette(page).locator('[data-search-tab=pages]')).toHaveAttribute('aria-selected', 'true');
    await expect(input(page)).toBeFocused();

    // T01 / T02: the exact name and without hamza.
    await searchPages(page, 'دفتر الاستاذ');
    await expect(rows(page).first()).toHaveAttribute('data-route', 'accounting.ledger.index');

    // T03: the section with every page it holds, its header is not a link and folds with Enter.
    await searchPages(page, 'المحاسبة');
    const section = rows(page).first();
    await expect(section).toHaveAttribute('data-section', 'accounting');
    await expect(section).toHaveAttribute('aria-expanded', 'true');
    await expect(section.locator('a')).toHaveCount(0);
    // The group's pages, then the accounting reports through their sub-heading («التقارير / المحاسبة»).
    for (const route of ['accounting.accounts.index', 'accounting.journals.index', 'accounting.ledger.index', 'accounting.trial-balance.index']) {
        await expect(rows(page).and(page.locator(`[data-route="${route}"]`))).toHaveCount(1);
    }
    await expect(page.locator('#gsearch-pages [data-route="accounting.ledger.index"]')).toContainText('التقارير / المحاسبة');
    await page.keyboard.press('Enter');
    await expect(section).toHaveAttribute('aria-expanded', 'false');
    await expect(page.locator('#gsearch-pages [data-route="accounting.journals.index"]')).toHaveCount(0);
    await page.keyboard.press('Enter');
    await expect(section).toHaveAttribute('aria-expanded', 'true');

    // T17: ↓ moves, Enter opens the page.
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('ArrowDown');
    await expect(page.locator('#gsearch-pages [aria-selected=true]')).toHaveAttribute('data-route', 'accounting.journals.index');
    await expect(input(page)).toHaveAttribute('aria-activedescendant', 'gsearch-row-2');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/accounting\/journals$/);
    await expect(palette(page)).toHaveCount(0);

    // T04, T05, T25: a menu with the page registered under it; other names; a page outside the sidebar.
    await openPages(page);
    await searchPages(page, 'الإعدادات العامة');
    await expect(rows(page).nth(0)).toHaveAttribute('data-route', 'settings.index');
    await expect(rows(page).nth(1)).toHaveAttribute('data-route', 'settings.sector-packs');
    await expect(rows(page).nth(1)).toContainText('الإعدادات / الإعدادات العامة');
    await searchPages(page, 'الزبائن');
    await expect(rows(page).first()).toHaveAttribute('data-route', 'sales.clients.index');
    await searchPages(page, 'الاستاذ العام');
    await expect(rows(page).first()).toHaveAttribute('data-route', 'accounting.ledger.index');
    await searchPages(page, 'الإشعارات');
    await expect(rows(page).first()).toHaveAttribute('data-route', 'notifications.index');

    // T15: same name, two places: both, each with its path.
    await searchPages(page, 'إقرار ضريبة القيمة المضافة');
    await expect(rows(page)).toHaveCount(2);
    await expect(rows(page).nth(0)).toContainText('التقارير / عامة');
    await expect(rows(page).nth(1)).toContainText('التقارير / الضرائب');

    // Esc closes; opening again (same page) starts on «السجلات» again.
    await page.keyboard.press('Escape');
    await expect(palette(page)).toHaveCount(0);
    await openWithKeyboard(page);
    await page.keyboard.press('Escape');
    expect(errors).toEqual([]);
});

test('pages: no made-up result; Enter then searches the records, which work as before', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    await openPages(page);

    // T06: no supplier groups page in Diwan: no result, no link.
    await searchPages(page, 'مجموعات الموردين');
    await expect(palette(page).locator('[data-search-pages-empty]')).toBeVisible();
    await expect(rows(page)).toHaveCount(0);
    await expect(palette(page).locator('[data-search-pages] a')).toHaveCount(0);

    // T19: the records tab searches records as before (chips, groups, open a record).
    await searchPages(page, 'البشائر');
    const search = page.waitForResponse((r) => r.url().includes('/search?') && r.status() === 200);
    await page.keyboard.press('Enter');
    await search;
    await expect(palette(page).locator('[data-search-tab=records]')).toHaveAttribute('aria-selected', 'true');
    await expect(palette(page).locator('[data-search-kinds] button').first()).toBeVisible();
    // The customer itself (its code #C-…), not an invoice that carries its name.
    const record = palette(page).getByRole('option', { name: /مؤسسة البشائر الحديثة/ }).filter({ hasText: '#C-' }).first();
    await expect(record).toBeVisible();
    await record.click();
    await expect(page).toHaveURL(/\/sales\/clients\/\d+$/);

    // The palette opens on «السجلات» again; the pages tab sends no request while typing.
    await openPages(page);
    let requests = 0;
    page.on('request', (r) => { if (r.url().includes('/search?')) requests += 1; });
    await input(page).pressSequentially('القيود اليومية', { delay: 20 });
    await expect(rows(page).first()).toHaveAttribute('data-route', 'accounting.journals.index');
    expect(requests).toBe(0);

    // T22: markup typed in the search stays text.
    let dialogs = 0;
    page.on('dialog', async (d) => { dialogs += 1; await d.dismiss(); });
    await input(page).fill('<img src=x onerror=alert(1)><b>x</b>');
    await expect(palette(page).locator('[data-search-pages-empty]')).toContainText('<img src=x onerror=alert(1)><b>x</b>');
    await expect(palette(page).locator('img')).toHaveCount(0);
    expect(dialogs).toBe(0);
    expect(errors).toEqual([]);
});

test('pages: a module switched off leaves the search and its URL is refused; switched on, it is back', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = watchErrors(page);
    try {
        setDisabled(`['manufacturing']`);
        await page.goto('/dashboard', { waitUntil: 'networkidle' });
        await openPages(page);
        await searchPages(page, 'أوامر الإنتاج');
        await expect(palette(page).locator('[data-search-pages-empty]')).toBeVisible();
        await searchPages(page, 'التصنيع');
        await expect(page.locator('#gsearch-pages [data-section="manufacturing"]')).toHaveCount(0);
        expect(await statusOf(page, '/manufacturing/orders')).toBe(403);

        setDisabled('[]');
        await page.reload({ waitUntil: 'networkidle' });
        await openPages(page);
        await searchPages(page, 'التصنيع');
        await expect(rows(page).first()).toHaveAttribute('data-section', 'manufacturing');
        await searchPages(page, 'أوامر الإنتاج');
        await expect(rows(page).first()).toHaveAttribute('data-route', 'manufacturing.orders.index');
    } finally {
        setDisabled('[]');
    }
    expect(errors.filter((e) => !e.includes('403'))).toEqual([]);
});

test('pages: a cashier sees only what a cashier may open, and a granted permission shows on the next page', async ({ page }) => {
    test.setTimeout(120_000);
    const sfx = String(Date.now()).slice(-6);
    const email = `cashier-${sfx}@diwan.io`;
    tinker(`$c = App\\Models\\Company::first(); app(App\\Services\\TenantManager::class)->runAs($c, function () use ($c) { $u = (new App\\Models\\User)->forceFill(['company_id' => $c->id, 'name' => 'كاشير ${sfx}', 'email' => '${email}', 'password' => Illuminate\\Support\\Facades\\Hash::make('password'), 'status' => 'active', 'is_super_admin' => false, 'email_verified_at' => now()]); $u->save(); $u->assignRole('cashier'); }); echo 'ok';`);
    await page.context().clearCookies();
    await page.goto('/login');
    await page.locator('input[type=email]').fill(email);
    await page.locator('input[type=password]').fill('password');
    await page.locator('button[type=submit]').click();
    // A cashier may not open the dashboard the login leads to; the notifications page is open to every user.
    await page.waitForResponse((r) => r.url().includes('/dashboard'));
    await page.goto('/notifications', { waitUntil: 'networkidle' });
    await expect(page).toHaveURL(/\/notifications$/);
    const errors = watchErrors(page);
    await openPages(page);
    // T08: not the ledger, not the accounting section (T26), but the cashier's own pages.
    await searchPages(page, 'دفتر الأستاذ');
    await expect(palette(page).locator('[data-search-pages-empty]')).toBeVisible();
    await searchPages(page, 'المحاسبة');
    await expect(page.locator('#gsearch-pages [data-section="accounting"]')).toHaveCount(0);
    await searchPages(page, 'العملاء');
    await expect(rows(page).first()).toHaveAttribute('data-route', 'sales.clients.index');
    // T14: the server refuses the hidden page by URL.
    expect(await statusOf(page, '/accounting/ledger')).toBe(403);

    // T13: a permission granted mid-session shows on the next page.
    tinker(`$u = App\\Models\\User::where('email', '${email}')->first(); app(Spatie\\Permission\\PermissionRegistrar::class)->setPermissionsTeamId($u->company_id); $u->givePermissionTo('accounting.ledger.view'); echo 'ok';`);
    await page.keyboard.press('Escape');
    await page.goto('/notifications', { waitUntil: 'networkidle' });
    await openPages(page);
    await searchPages(page, 'دفتر الأستاذ');
    await expect(rows(page).first()).toHaveAttribute('data-route', 'accounting.ledger.index');
    await page.keyboard.press('Enter');
    await expect(page).toHaveURL(/\/accounting\/ledger$/);
    expect(errors.filter((e) => !e.includes('403'))).toEqual([]);
});

test('pages: the index follows an Inertia visit that changes the modules, without a reload', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = watchErrors(page);
    try {
        setDisabled('[]');
        await page.goto('/settings/modules', { waitUntil: 'networkidle' });
        await openPages(page);
        await searchPages(page, 'التصنيع');
        await expect(rows(page).first()).toHaveAttribute('data-section', 'manufacturing');
        await page.keyboard.press('Escape');

        // Switch manufacturing off on the settings page itself: an Inertia request, the page is not reloaded.
        await page.locator('[data-module="manufacturing"]').click();
        const saved = page.waitForResponse((r) => r.request().method() === 'PUT' && r.url().includes('/settings/modules'));
        await page.locator('[data-save-modules]').click();
        await saved;
        await page.waitForLoadState('networkidle');
        await openPages(page);
        await searchPages(page, 'التصنيع');
        await expect(page.locator('#gsearch-pages [data-section="manufacturing"]')).toHaveCount(0);
        await searchPages(page, 'أوامر الإنتاج');
        await expect(palette(page).locator('[data-search-pages-empty]')).toBeVisible();
        await page.keyboard.press('Escape');

        // And back on.
        await page.locator('[data-module="manufacturing"]').click();
        const back = page.waitForResponse((r) => r.request().method() === 'PUT' && r.url().includes('/settings/modules'));
        await page.locator('[data-save-modules]').click();
        await back;
        await page.waitForLoadState('networkidle');
        await openPages(page);
        await searchPages(page, 'أوامر الإنتاج');
        await expect(rows(page).first()).toHaveAttribute('data-route', 'manufacturing.orders.index');
    } finally {
        setDisabled('[]');
    }
    expect(errors).toEqual([]);
});

test('pages: mouse — a section click keeps the keyboard, right click stays, middle click and Ctrl/⌘ open a new tab', async ({ page, context }) => {
    const errors = watchErrors(page);
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    await openPages(page);
    await searchPages(page, 'المحاسبة');
    const section = rows(page).first();
    await section.click();
    await expect(section).toHaveAttribute('aria-expanded', 'false');
    await expect(input(page)).toBeFocused();
    await page.keyboard.press('Enter');
    await expect(section).toHaveAttribute('aria-expanded', 'true');

    // Right click: the browser's menu, no navigation.
    const ledger = page.locator('#gsearch-pages [data-route="accounting.ledger.index"]');
    await ledger.click({ button: 'right' });
    await page.waitForTimeout(300);
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(palette(page)).toBeVisible();

    // Middle click and Ctrl/⌘+click: a new browser tab, this one stays.
    const viaMiddle = context.waitForEvent('page');
    await ledger.click({ button: 'middle' });
    const tabA = await viaMiddle;
    await expect(tabA).toHaveURL(/\/accounting\/ledger$/);
    await tabA.close();
    const viaModifier = context.waitForEvent('page');
    await ledger.click({ modifiers: ['ControlOrMeta'] });
    const tabB = await viaModifier;
    await expect(tabB).toHaveURL(/\/accounting\/ledger$/);
    await tabB.close();
    await expect(page).toHaveURL(/\/dashboard$/);
    expect(errors).toEqual([]);
});

test('Ctrl/⌘+Enter in the palette on a create page does not submit the form under it', async ({ page, context }) => {
    const errors = watchErrors(page);
    await page.goto('/sales/clients/create', { waitUntil: 'networkidle' });
    let posted = 0;
    page.on('request', (r) => { if (r.method() === 'POST' && /\/sales\/clients$/.test(new URL(r.url()).pathname)) posted += 1; });
    await openPages(page);
    await searchPages(page, 'دفتر الأستاذ');
    await expect(rows(page).first()).toHaveAttribute('data-route', 'accounting.ledger.index');
    const newTab = context.waitForEvent('page');
    await page.keyboard.press('ControlOrMeta+Enter');
    const opened = await newTab;
    await expect(opened).toHaveURL(/\/accounting\/ledger$/);
    await opened.close();
    await page.waitForTimeout(500);
    expect(posted).toBe(0);
    await expect(page).toHaveURL(/\/sales\/clients\/create$/);
    expect(errors).toEqual([]);
});

test('records: chips, Backspace, ↑ ↓ and Enter work as before', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    await openWithKeyboard(page);
    await palette(page).locator('[data-search-tab=records]').click();
    const chips = palette(page).locator('[data-search-kinds] button');
    await expect(chips.first()).toBeVisible();
    await chips.filter({ hasText: 'العملاء' }).first().click();
    await expect(input(page)).toHaveAttribute('placeholder', /العملاء/);
    // Backspace in an empty box drops the chip.
    await input(page).focus();
    await page.keyboard.press('Backspace');
    await expect(input(page)).toHaveAttribute('placeholder', 'ابحث برقم أو اسم أو وصف...');

    const reply = page.waitForResponse((r) => r.url().includes('/search?') && r.url().includes('q='));
    await input(page).pressSequentially('شركة', { delay: 20 });
    await reply;
    const options = palette(page).getByRole('option');
    await expect(options.nth(1)).toBeVisible();
    await expect(options.nth(0)).toHaveAttribute('aria-selected', 'true');
    await page.keyboard.press('ArrowDown');
    await expect(options.nth(1)).toHaveAttribute('aria-selected', 'true');
    await expect(input(page)).toHaveAttribute('aria-activedescendant', 'gsearch-rec-1');
    await page.keyboard.press('ArrowUp');
    await expect(options.nth(0)).toHaveAttribute('aria-selected', 'true');
    await page.keyboard.press('Enter');
    await expect(palette(page)).toHaveCount(0);
    await expect(page).not.toHaveURL(/\/dashboard$/);
    expect(errors).toEqual([]);
});

test('the «إضافة» and «عرض» menus are as before', async ({ page }) => {
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    await page.locator('[data-quick-add]').click();
    const add = page.locator('[data-quick-add-panel] a');
    await expect(add.first()).toBeVisible();
    await expect(add.filter({ hasText: 'فاتورة مبيعات' })).toHaveAttribute('href', /\/sales\/invoices\/create$/);
    await expect(add.filter({ hasText: 'قيد يومي' })).toHaveAttribute('href', /\/accounting\/journals\/create$/);
    await page.locator('[data-quick-add]').click();
    await page.locator('[data-quick-view]').click();
    const view = page.locator('[data-quick-view-panel] a');
    await expect(view.filter({ hasText: 'دليل الحسابات' })).toHaveAttribute('href', /\/accounting\/accounts$/);
    await expect(view.filter({ hasText: 'القيود اليومية' })).toHaveAttribute('href', /\/accounting\/journals$/);
    await view.filter({ hasText: 'القيود اليومية' }).click();
    await expect(page).toHaveURL(/\/accounting\/journals$/);
});

test.describe('phone', () => {
    test.use({ viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true });

    test('T18 the palette fits the phone and a tap opens a page', async ({ page }) => {
        const errors = watchErrors(page);
        await page.goto('/dashboard', { waitUntil: 'networkidle' });
        await page.locator('[data-search-open]').tap();
        await expect(palette(page)).toBeVisible();
        await expect(palette(page).locator('[data-search-tab=records]')).toHaveAttribute('aria-selected', 'true');
        await palette(page).locator('[data-search-tab=pages]').tap();
        await input(page).fill('المبيعات');
        await expect(rows(page).first()).toHaveAttribute('data-section', 'sales');
        const box = await palette(page).locator('[role=dialog]').boundingBox();
        expect(box.width).toBeLessThanOrEqual(375);
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(375);
        await page.locator('#gsearch-pages [data-route="sales.customer-groups.index"]').tap();
        await expect(page).toHaveURL(/\/sales\/customer-groups$/);
        expect(errors).toEqual([]);
    });
});
