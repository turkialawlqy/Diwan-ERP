import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { watchErrors } from './helpers.js';

/* Branch activities: the working branch runs only the activities listed for it (menu and direct URL). */
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], {
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: `${process.cwd()}/database/e2e.sqlite`, APP_ENV: 'local', CACHE_STORE: 'array' }, encoding: 'utf8',
}).trim().split('\n').pop();

const setModules = (json) => tinker(`$c = App\\Models\\Company::first(); app(App\\Services\\TenantManager::class)->runAs($c, function () { App\\Models\\Branch::withoutGlobalScopes()->update(['modules' => ${json} ]); }); echo 'ok';`);

test('branch activities: a branch without an activity answers 403 and hides it', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = watchErrors(page);
    try {
        // The branch runs the clinic only.
        setModules(`json_encode(['clinic'])`);
        await page.goto('/clinic/patients', { waitUntil: 'networkidle' });
        await expect(page.getByRole('heading', { name: /ملفات المرضى|المرضى/ }).first()).toBeVisible();
        const res = await page.goto('/workshop/vehicles');
        expect(res.status()).toBe(403);

        // Every activity again (no list).
        setModules('null');
        const ok = await page.goto('/workshop/vehicles', { waitUntil: 'networkidle' });
        expect(ok.status()).toBe(200);
    } finally {
        setModules('null');
    }
    expect(errors.filter((e) => !/403/.test(e))).toEqual([]);
});
