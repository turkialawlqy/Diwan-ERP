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
