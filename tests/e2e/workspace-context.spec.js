import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

/*
 * «سياق العمل» (company, branch, fiscal year) at the top of the sidebar, and the
 * top bar without the context selects. The choice lives in the server session,
 * so every test signs in on a session of its own: nothing it picks reaches the
 * other specs.
 */
test.use({ storageState: { cookies: [], origins: [] } });

const MAIN = 'الفرع الرئيسي';
const JEDDAH = 'فرع المنطقة الغربية - جدة';
const DAMMAM = 'فرع المنطقة الشرقية - الدمام';

async function signIn(page) {
    await page.goto('/login');
    await page.locator('input[type=email]').fill('anas@diwan.io');
    await page.locator('input[type=password]').fill('password');
    await page.locator('button[type=submit]').click();
    await expect(page).not.toHaveURL(/\/login/);
}

const card = (page) => page.locator('[data-context-card]');
const panel = (page) => page.locator('[data-context-panel]');
const summary = (page) => page.locator('[data-context-summary]');

// Opens the popover unless it is open already: dismissing the unsaved-changes dialog by its
// backdrop leaves it open (Headless UI counts the dialog, teleported to <body>, as another root).
async function switchBranch(page, label) {
    if ((await card(page).getAttribute('aria-expanded')) !== 'true') {
        await expect(panel(page)).toHaveCount(0); // a popover that was closing is gone
        await card(page).click();
    }
    await panel(page).locator('[data-context-branch]').selectOption({ label });
    await panel(page).locator('[data-context-apply]').click();
}

test('the top bar keeps its tools; company, branch and year are in the sidebar card', async ({ page }) => {
    const errors = watchErrors(page);
    await signIn(page);
    await page.goto('/dashboard');

    const header = page.locator('header');
    for (const tool of ['[data-search-open]', '[data-quick-add]', '[data-quick-view]', '[data-pos-link]', '[data-user-menu]']) {
        await expect(header.locator(tool)).toBeVisible();
    }
    await expect(header.getByTitle('الإشعارات')).toBeVisible();
    await expect(header.locator('select')).toHaveCount(0);
    const heights = await header.locator('[data-search-open], [data-quick-add], [data-quick-view], [data-pos-link], [data-user-menu]')
        .evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().height)));
    expect(new Set(heights).size).toBe(1);

    // Language is in the user menu; «عرض» holds only links.
    await header.locator('[data-user-menu]').click();
    await expect(page.locator('[data-language-toggle]')).toHaveText(/English/);
    await page.keyboard.press('Escape');
    await header.locator('[data-quick-view]').click();
    await expect(page.locator('[data-quick-view-panel] a').first()).toBeVisible();
    await expect(page.locator('[data-quick-view-panel] button')).toHaveCount(0);
    await page.keyboard.press('Escape');

    // Full screen (where the browser has it): an icon with its name on hover, between split screen and the notifications.
    if (await page.evaluate(() => document.fullscreenEnabled)) {
        const full = header.locator('[data-fullscreen-toggle]');
        await expect(full).toHaveAttribute('title', 'ملء الشاشة');
        await expect(full).toHaveAttribute('aria-label', 'ملء الشاشة');
        await expect(full).toHaveText('');
        const order = await header.locator('[data-split-toggle], [data-fullscreen-toggle], [title="الإشعارات"]')
            .evaluateAll((els) => els.map((el) => ('splitToggle' in el.dataset ? 'split' : 'fullscreenToggle' in el.dataset ? 'full' : 'bell')));
        expect(order).toEqual(['split', 'full', 'bell']);

        await full.click();
        await expect.poll(() => page.evaluate(() => !!document.fullscreenElement)).toBe(true);
        await expect(full).toHaveAttribute('title', 'الخروج من ملء الشاشة');
        await full.click();
        await expect.poll(() => page.evaluate(() => !!document.fullscreenElement)).toBe(false);
        await expect(full).toHaveAttribute('title', 'ملء الشاشة');
    }

    await expect(card(page)).toContainText('مؤسسة أنس التجارية');
    await expect(summary(page)).toContainText(MAIN);
    await expect(card(page)).toHaveAttribute('aria-expanded', 'false');
    expect(errors).toEqual([]);
});

test('the branch changes only on «تطبيق التغيير» and stays across pages and reloads', async ({ page }) => {
    const errors = watchErrors(page);
    await signIn(page);
    await page.goto('/sales/invoices');

    await card(page).click();
    await expect(panel(page)).toBeVisible();
    await expect(panel(page).locator('[data-context-company]')).toContainText('مؤسسة أنس التجارية');
    const apply = panel(page).locator('[data-context-apply]');
    await expect(apply).toBeDisabled();

    // Escape closes without applying anything.
    await panel(page).locator('[data-context-branch]').selectOption({ label: JEDDAH });
    await page.keyboard.press('Escape');
    await expect(panel(page)).toBeHidden();
    await expect(card(page)).toBeFocused();
    await expect(summary(page)).toContainText(MAIN);

    // Reopened: the current values again, then applied.
    await card(page).click();
    await expect(panel(page).locator('[data-context-branch] option:checked')).toHaveText(MAIN);
    await panel(page).locator('[data-context-branch]').selectOption({ label: JEDDAH });
    await apply.click();
    await expect(page.locator('body')).toContainText(`تم تغيير سياق العمل: ${JEDDAH}`);
    await expect(panel(page)).toBeHidden();
    await expect(summary(page)).toContainText(JEDDAH);

    await page.locator('aside').getByRole('link', { name: 'لوحة التحكم' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(summary(page)).toContainText(JEDDAH);
    await page.reload();
    await expect(summary(page)).toContainText(JEDDAH);
    expect(errors).toEqual([]);
});

test('Back after a switch shows the page again with the new branch, not the old data', async ({ page }) => {
    await signIn(page);
    await page.goto('/sales/invoices');
    await page.locator('aside').getByRole('link', { name: 'لوحة التحكم' }).click();
    await expect(page).toHaveURL(/\/dashboard$/);

    await switchBranch(page, DAMMAM);
    await expect(summary(page)).toContainText(DAMMAM);

    await page.goBack();
    await expect(page).toHaveURL(/\/sales\/invoices$/);
    await expect(summary(page)).toContainText(DAMMAM);
});

test('unsaved changes are protected before switching', async ({ page }) => {
    await signIn(page);
    await page.goto('/sales/customer-groups/create');
    const name = page.getByRole('textbox', { name: 'اسم المجموعة' });
    await name.fill('مجموعة اختبار السياق');

    // Stay: nothing switched, nothing lost.
    await switchBranch(page, JEDDAH);
    await expect(page.locator('[data-unsaved-dialog]')).toBeVisible();
    await page.mouse.click(5, 5);
    await expect(page.locator('[data-unsaved-dialog]')).toBeHidden();
    await expect(name).toHaveValue('مجموعة اختبار السياق');
    await expect(summary(page)).toContainText(MAIN);

    // Leave without saving: switched, and the form starts again in the new branch.
    await switchBranch(page, JEDDAH);
    await page.locator('[data-unsaved-discard]').click();
    await expect(summary(page)).toContainText(JEDDAH);
    await expect(page).toHaveURL(/\/sales\/customer-groups\/create$/);
    await expect(name).toHaveValue('');
});

test('collapsed sidebar: an icon with a tooltip opens the popover beside the rail', async ({ page }) => {
    const errors = watchErrors(page);
    await signIn(page);
    await page.goto('/dashboard');
    await page.locator('[data-sidebar-toggle]').click();
    // Hover only once the rail has stopped narrowing, or the icon slides out from under the mouse.
    await expect(page.locator('aside')).toHaveCSS('width', '80px');

    await expect(summary(page)).toBeHidden();
    await card(page).hover();
    const tooltip = page.locator('[data-context-tooltip]');
    await expect(tooltip).toHaveCSS('opacity', '1');
    await expect(tooltip).toContainText('مؤسسة أنس التجارية');
    await expect(tooltip).toContainText(`الفرع: ${MAIN}`);
    await expect(tooltip).toContainText('السنة المالية');

    await card(page).click();
    await expect(panel(page)).toBeVisible();
    const box = await panel(page).boundingBox();
    const rail = await page.locator('aside').boundingBox();
    expect(box.x + box.width).toBeLessThanOrEqual(rail.x); // RTL: just left of the rail
    expect(box.x).toBeGreaterThanOrEqual(0);
    await page.keyboard.press('Escape');

    await page.locator('[data-sidebar-toggle]').click();
    await expect(summary(page)).toBeVisible();
    expect(errors).toEqual([]);
});

test('phones: no sideways scrolling, and the card works in the drawer', async ({ page }) => {
    await signIn(page);
    for (const width of [320, 360, 414]) {
        await page.setViewportSize({ width, height: 740 });
        await page.goto('/sales/customer-groups/create');
        expect(await page.locator('header').evaluate((h) => h.scrollWidth - h.clientWidth)).toBe(0);
        expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(0);
    }

    await page.getByRole('button', { name: 'القائمة', exact: true }).click();
    await expect(page.locator('aside')).toHaveCSS('transform', 'matrix(1, 0, 0, 1, 0, 0)'); // the drawer has slid in
    await card(page).click();
    await expect(panel(page)).toBeVisible();
    const box = await panel(page).boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(414);
    expect(box.y + box.height).toBeLessThanOrEqual(740);
});
