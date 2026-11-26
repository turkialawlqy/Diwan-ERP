import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { watchErrors } from './helpers.js';

/* Dashboard widgets: a system's card follows the company switch and the user's own hide / show. */
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], {
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: `${process.cwd()}/database/e2e.sqlite`, APP_ENV: 'local', CACHE_STORE: 'array' }, encoding: 'utf8',
}).trim().split('\n').pop();
const setDisabled = (json) => tinker(`$c = App\\Models\\Company::first(); $c->setSetting('disabled_modules', ${json}, 'general', 'json'); echo 'ok';`);

test('dashboard widgets: follow the enabled systems and each user\'s choice', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = watchErrors(page);
    try {
        setDisabled('[]');
        await page.goto('/dashboard', { waitUntil: 'networkidle' });
        await expect(page.locator('[data-widget="support"]')).toBeVisible();
        await expect(page.locator('[data-widget="bookings"]')).toBeVisible();

        // Hide one from the customise menu; it comes back when ticked again.
        await page.locator('[data-customize]').click();
        await page.locator('[data-toggle="support"]').uncheck();
        await expect(page.locator('[data-widget="support"]')).toHaveCount(0);
        await page.locator('[data-toggle="support"]').check();
        await expect(page.locator('[data-widget="support"]')).toBeVisible();

        // The company switches the system off: its card goes (and is not even in the customise list).
        setDisabled(`['support']`);
        await page.goto('/dashboard', { waitUntil: 'networkidle' });
        await expect(page.locator('[data-widget="support"]')).toHaveCount(0);
        await expect(page.locator('[data-widget="bookings"]')).toBeVisible();
    } finally {
        setDisabled('[]');
    }
    expect(errors).toEqual([]);
});

/* The welcome banner's summary runs under the title and the buttons (one line wherever it fits), and the seeded
   overdue invoices (September 2026, 30 days) always bring the «منها» part, which needs its space. */
test('welcome banner: the summary spans the banner, with a space before «منها»', async ({ page }) => {
    const errors = watchErrors(page);
    const summary = page.locator('[data-welcome-summary]');
    for (const width of [768, 1280, 1440]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/dashboard', { waitUntil: 'networkidle' });
        await expect(summary).toContainText(/غير مسددة منها \d+ متأخرة/);
        const { lines, gap } = await summary.evaluate((p) => {
            const box = p.closest('[data-welcome]');
            const cs = getComputedStyle(box);
            const inner = box.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight);
            const r = p.getBoundingClientRect();
            return { lines: Math.round(r.height / parseFloat(getComputedStyle(p).lineHeight)), gap: Math.abs(r.width - inner) };
        });
        expect(gap, `${width}px: full banner width`).toBeLessThan(2);
        if (width >= 1280) expect(lines, `${width}px: one line`).toBe(1);
    }

    // A phone keeps the order: title, summary, buttons.
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    const tops = await page.locator('[data-welcome] > *').evaluateAll((els) => els.map((el) => el.getBoundingClientRect().top));
    expect(tops).toEqual([...tops].sort((a, b) => a - b));
    expect(errors).toEqual([]);
});
