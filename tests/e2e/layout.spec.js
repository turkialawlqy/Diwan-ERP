import { test, expect } from '@playwright/test';

/*
 * The page never scrolls sideways: wide content (tables, long names, amounts)
 * scrolls or truncates inside its own card. Checked at phone, tablet, laptop and
 * desktop widths, with the sidebar expanded and collapsed (kept in localStorage).
 */
const PAGES = [
    '/dashboard', '/sales/invoices', '/inventory/products',
    // Date fields in narrow cards and toolbars, a payroll button row, settings rows, document lines.
    '/hr/leaves', '/manufacturing/reports', '/hr/payroll', '/hr/settings', '/purchases/invoices/create', '/sales/invoices/create',
    // Release notes: filter toolbar and month cards.
    '/whats-new',
];
const WIDTHS = [375, 768, 1024, 1440];

const sidewaysOverflow = (page) => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);

// One test per width, so each stays well inside the per-test timeout on a slow CI runner.
// The collapsed rail exists from lg (1024px) up; below that the sidebar is a drawer either way.
for (const collapsed of [false, true]) {
    for (const width of WIDTHS.filter((w) => !collapsed || w >= 1024)) {
        test(`no sideways scrolling at ${width}px, sidebar ${collapsed ? 'collapsed' : 'expanded'}`, async ({ page }) => {
            await page.addInitScript((value) => localStorage.setItem('diwan.sidebar.collapsed', value), collapsed ? '1' : '0');
            await page.setViewportSize({ width, height: 900 });
            for (const path of PAGES) {
                await page.goto(path, { waitUntil: 'networkidle' });
                expect.soft(await sidewaysOverflow(page), `${path} at ${width}px`).toBe(0);
            }
        });
    }
}

test('phones: list tables scroll inside their card', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    for (const path of ['/sales/invoices', '/inventory/products']) {
        await page.goto(path, { waitUntil: 'networkidle' });
        const scroller = page.locator('table').first().locator('xpath=..');
        const { visible, content } = await scroller.evaluate((el) => ({ visible: el.clientWidth, content: el.scrollWidth }));
        expect(content, `${path}: the table is wider than the phone`).toBeGreaterThan(visible);
        expect(visible, `${path}: the card fits the phone`).toBeLessThanOrEqual(375);

        // RTL: scrolling towards the end side is a negative scrollLeft.
        const moved = await scroller.evaluate((el) => { el.scrollLeft = -100; return el.scrollLeft; });
        expect(moved, `${path}: the table scrolls inside its card`).toBeLessThan(0);
        expect(await sidewaysOverflow(page), path).toBe(0);
    }
});

test('phones: invoice lines scroll in their card and the product list is not cut off', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    for (const [path, placeholder] of [['/purchases/invoices/create', 'اختر أو اكتب اسم منتج للإنشاء...'], ['/sales/invoices/create', 'اختر أو انشئ منتج...']]) {
        await page.goto(path, { waitUntil: 'networkidle' });
        const scroller = page.locator('table').first().locator('xpath=..');
        const moved = await scroller.evaluate((el) => { el.scrollLeft = -100; return el.scrollLeft; });
        expect(moved, `${path}: the lines scroll inside their card`).toBeLessThan(0);

        await page.getByPlaceholder(placeholder).first().click();
        const list = page.locator('[data-product-list]');
        await expect(list).toBeVisible();
        // A scroll box around the table would clip the list; its far edge must still be on top.
        const shown = await list.evaluate((el) => {
            const r = el.getBoundingClientRect();
            const x = r.left + r.width / 2;
            return [r.top + 4, r.bottom - 4].every((y) => el.contains(document.elementFromPoint(x, y)));
        });
        expect(shown, `${path}: the whole product list is visible`).toBe(true);
    }
});

test('line tables: the SelectCreate list is not cut off by the table box', async ({ page }) => {
    for (const [width, height] of [[375, 812], [1440, 900]]) {
        await page.setViewportSize({ width, height });
        for (const path of ['/sales/quotations/create', '/manufacturing/boms/create', '/manufacturing/routings/create']) {
            await page.goto(path, { waitUntil: 'networkidle' });
            const field = page.locator('table [data-select-create] > input').first();
            await field.click();
            const list = page.locator('[data-select-list]');
            await expect(list).toBeVisible();
            const { shown, attached } = await list.evaluate((el, input) => {
                const r = el.getBoundingClientRect();
                const f = input.getBoundingClientRect();
                const x = r.left + r.width / 2;
                const near = (a, b) => Math.abs(a - b) < 1.5;
                return {
                    // The table's scroll box would clip an absolute list; its far edge must still be on top.
                    shown: r.left >= 0 && r.right <= innerWidth && [r.top + 4, r.bottom - 4].every((y) => el.contains(document.elementFromPoint(x, y))),
                    // 4px under (or above) the field, over the field's start edge (the right one in Arabic).
                    attached: (near(r.top, f.bottom + 4) || near(r.bottom, f.top - 4)) && r.left < f.right && r.right >= f.right - 0.5,
                };
            }, await field.elementHandle());
            expect.soft(shown, `${path} at ${width}px: the whole list is visible`).toBe(true);
            expect.soft(attached, `${path} at ${width}px: the list sits at its field`).toBe(true);
        }
    }

    // Typing a new name still offers «إنشاء "…"», and Enter opens the create dialog.
    await page.goto('/sales/quotations/create', { waitUntil: 'networkidle' });
    const field = page.locator('table [data-select-create] > input').first();
    await field.click();
    await field.pressSequentially('صنف لم يُسجَّل بعد', { delay: 20 });
    await expect(page.locator('[data-select-list] [data-create-option]')).toBeVisible();
    await field.press('Enter');
    await expect(page.locator('[data-quick-create]')).toBeVisible();
});
