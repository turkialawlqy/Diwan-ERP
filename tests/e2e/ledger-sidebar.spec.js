import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

/*
 * The sidebar keeps showing the page you are on after a refresh (even in a short window, even when the
 * icon rail is on), the ledger account picker searches by number and by name, and the consolidated
 * statement puts several accounts in one statement.
 */

test('the sidebar still shows the selected page after a refresh, in a short window', async ({ page }) => {
    const errors = watchErrors(page);
    await page.setViewportSize({ width: 1400, height: 520 });
    await page.goto('/accounting/ledger');
    const current = page.locator('[data-sidebar] [data-nav-current]').first();
    await expect(current).toBeVisible();
    await page.reload();
    await expect(page.locator('[data-sidebar] [data-nav-current]').first()).toBeInViewport();
    expect(errors).toEqual([]);
});

test('with the icon rail on, the group of the current page is marked after a refresh', async ({ page }) => {
    await page.goto('/accounting/ledger');
    await page.evaluate(() => localStorage.setItem('diwan.sidebar.collapsed', '1'));
    await page.reload();
    // The rail has no room for links: the group holding the page is highlighted instead.
    await expect(page.locator('[data-sidebar] button.ring-1').first()).toBeVisible();
    await page.evaluate(() => localStorage.setItem('diwan.sidebar.collapsed', '0'));
});

test('groups opened by hand stay open on the next page', async ({ page }) => {
    await page.goto('/dashboard');
    await page.evaluate(() => localStorage.removeItem('diwan.sidebar.open'));
    await page.reload();
    await page.locator('[data-sidebar] nav button', { hasText: /الأصول الثابتة|المحاسبة/ }).first().click();
    const stored = await page.evaluate(() => localStorage.getItem('diwan.sidebar.open'));
    expect(JSON.parse(stored).length).toBeGreaterThan(0);
});

test('ledger account picker finds an account by its name and by its number', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/accounting/ledger');
    const picker = page.locator('[data-ledger-account]').first();
    await picker.click();
    await page.keyboard.type('الصندوق');
    await expect(page.locator('[data-select-list] button', { hasText: 'الصندوق' }).first()).toBeVisible();
    await page.keyboard.press('Escape');
    // By number: the first characters of a code.
    await picker.click();
    await page.keyboard.press('Control+A');
    await page.keyboard.type('11');
    await expect(page.locator('[data-select-list] button').first()).toBeVisible();
    expect(errors).toEqual([]);
});

test('consolidated statement: two accounts in one statement', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/accounting/statements/consolidated');
    await expect(page.locator('[data-consolidated-picker]').first()).toBeVisible();
    await page.locator('[data-consolidated-picker]').first().click();
    await page.keyboard.type('1');
    await page.locator('[data-select-list] button').first().click();
    await page.locator('[data-consolidated-picker]').first().click();
    await page.keyboard.type('4');
    await page.locator('[data-select-list] button').first().click();
    await page.locator('[data-consolidated-apply]').click();
    // A parent account (marked ▸) stands for every account below it, so the summary has several rows.
    await expect(page.locator('[data-consolidated-summary] tbody tr').nth(1)).toBeVisible();
    await expect(page.locator('[data-consolidated-lines]')).toBeVisible();
    expect(errors).toEqual([]);
});

test('each user orders the side menu with the arrows, and it stays after a refresh', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/dashboard');
    const firstGroup = () => page.locator('[data-sidebar] nav > button, [data-sidebar] nav > div > button').first();
    await page.locator('[data-sidebar-edit]').click();
    const rows = page.locator('[data-sidebar-group-row]');
    const before = await rows.allInnerTexts();
    expect(before.length).toBeGreaterThan(2);
    // Move the second group above the first with its arrow.
    await rows.nth(1).locator('[data-sidebar-group-up]').click();
    const moved = await rows.allInnerTexts();
    expect(moved[0]).toBe(before[1]);
    await page.locator('[data-sidebar-save]').click();
    await expect(page.locator('[data-sidebar-edit-bar]')).toHaveCount(0);
    await page.reload();
    await page.locator('[data-sidebar-edit]').click();
    const after = await page.locator('[data-sidebar-group-row]').allInnerTexts();
    expect(after[0]).toBe(before[1]);
    // Back to the default order so other specs see the usual menu.
    await page.locator('[data-sidebar-reset]').click();
    await expect(page.locator('[data-sidebar-edit-bar]')).toHaveCount(0);
    expect(errors).toEqual([]);
});

test('«الفروع», «قوالب الطباعة», «الإعدادات» and «التحديثات والطلبات» close the side menu, whatever order is saved', async ({ page }) => {
    const errors = watchErrors(page);
    const pinned = ['الفروع', 'قوالب الطباعة', 'الإعدادات', 'التحديثات والطلبات'];
    const entries = async () => (await page.locator('[data-sidebar] nav > a, [data-sidebar] nav > div > button').allInnerTexts()).map((text) => text.trim());
    // The saved order, sent from the page itself (its cookies and XSRF token; *.localhost resolves in the browser only).
    const order = (method, body = null) => page.evaluate(async ([method, body]) => {
        const xsrf = decodeURIComponent(document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1] || '');
        const headers = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf };
        // Saved = redirected back (not followed: fetch would repeat a DELETE on the page it goes back to).
        const response = await fetch('/preferences/sidebar', { method, headers, body: body && JSON.stringify(body), redirect: 'manual' });
        return response.type === 'opaqueredirect';
    }, [method, body]);
    await page.goto('/dashboard');
    expect((await entries()).slice(-4)).toEqual(pinned);

    try {
        // An order saved before the rule, with them on top, still shows them last; the other groups follow it.
        expect(await order('POST', { groups: ['updates', 'settings', 'templates', 'branches', 'sales'] })).toBe(true);
        await page.reload();
        const texts = await entries();
        expect(texts[0]).toBe('المبيعات');
        expect(texts.slice(-4)).toEqual(pinned);

        // While editing: no handle or arrows on them, and the last other group does not go down below them.
        await page.locator('[data-sidebar-edit]').click();
        const rows = page.locator('[data-sidebar-group-row]');
        const fixed = page.locator('[data-sidebar-group-row][data-sidebar-pinned]');
        await expect(fixed).toHaveCount(4);
        await expect(fixed.locator('[data-sidebar-group-up], [data-sidebar-group-down]')).toHaveCount(0);
        await expect(page.locator('[data-sidebar-pinned-note]')).toHaveCount(1);
        const before = await rows.allInnerTexts();
        const lastOther = before.length - 5;
        await rows.nth(lastOther).locator('[data-sidebar-group-down]').click();
        expect(await rows.allInnerTexts()).toEqual(before);
        // Drag and drop by a group's own header (not one of its links), downwards so that the target scrolls in at
        // the bottom and not under the sticky bar: nothing is dropped on them, they do not move, the others still do.
        // The window is made tall so that each drag stays inside the menu: a drag that has to scroll it drops nothing.
        const header = (row) => row.locator(':scope > div').first();
        await page.setViewportSize({ width: 1440, height: 3200 });
        await header(rows.nth(lastOther)).dragTo(header(fixed.nth(0)));
        expect(await rows.allInnerTexts()).toEqual(before);
        await header(fixed.nth(0)).dragTo(header(fixed.nth(3)));
        expect(await rows.allInnerTexts()).toEqual(before);
        await header(rows.nth(lastOther - 1)).dragTo(header(rows.nth(lastOther)));
        const dragged = await rows.allInnerTexts();
        expect([dragged[lastOther - 1], dragged[lastOther]]).toEqual([before[lastOther], before[lastOther - 1]]);
        expect(dragged.slice(-4)).toEqual(before.slice(-4));
        await page.locator('[data-sidebar-save]').click();
        await expect(page.locator('[data-sidebar-edit-bar]')).toHaveCount(0);
        expect((await entries()).slice(-4)).toEqual(pinned);
    } finally {
        // Back to the default order so other specs see the usual menu.
        await order('DELETE');
    }
    expect(errors).toEqual([]);
});

test('drag and drop reorders the links inside a group', async ({ page }) => {
    await page.goto('/dashboard');
    await page.locator('[data-sidebar-edit]').click();
    const group = page.locator('[data-sidebar-group-row]').filter({ has: page.locator('[data-sidebar-child-row]') }).first();
    const children = group.locator('[data-sidebar-child-row]');
    const before = await children.allInnerTexts();
    expect(before.length).toBeGreaterThan(1);
    await children.nth(0).dragTo(children.nth(1));
    const after = await children.allInnerTexts();
    expect(after[1]).toBe(before[0]);
    await page.locator('[data-sidebar-cancel]').click();
});
