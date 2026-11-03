import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { watchErrors } from './helpers.js';

/* Administrative requests: summon two employees of different departments, send, follow every line to the end. */
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], {
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: `${process.cwd()}/database/e2e.sqlite`, APP_ENV: 'local', CACHE_STORE: 'array' }, encoding: 'utf8',
}).trim().split('\n').pop();

test('administrative requests: create, send and follow up every employee line', async ({ page }) => {
    test.setTimeout(180_000);
    const errors = watchErrors(page);
    const sfx = String(Date.now()).slice(-5);
    tinker(`$c = App\\Models\\Company::first(); app(App\\Services\\TenantManager::class)->runAs($c, function () { foreach (['أول${sfx}' => 'إدارة أ${sfx}', 'ثان${sfx}' => 'إدارة ب${sfx}'] as $n => $d) { $dep = App\\Models\\Department::create(['name' => $d]); App\\Models\\Employee::create(['name' => $n, 'department_id' => $dep->id, 'status' => 'active', 'job_title' => 'موظف', 'hire_date' => '2024-01-01', 'basic_salary' => 3000, 'employee_number' => 'AR${sfx}'.mb_substr($n, 0, 1)]); } }); echo 'ok';`);

    await page.goto('/hr/admin-requests/create', { waitUntil: 'networkidle' });
    await page.locator('[data-title]').fill(`اجتماع ${sfx}`);
    for (const n of [`أول${sfx}`, `ثان${sfx}`]) {
        await page.locator('[data-employee-picker] input').first().fill(n);
        await page.locator('[data-select-list] button:not([data-create-option])', { hasText: n }).first().click();
    }
    await expect(page.locator('[data-employee-line]')).toHaveCount(2);
    await page.locator('[data-save-send]').click();
    await expect(page.locator('[data-request-status]')).toHaveText('جديد');

    for (const n of [`أول${sfx}`, `ثان${sfx}`]) {
        const row = page.locator(`[data-line="${n}"]`);
        await row.locator('[data-act="receive"]').click();
        await expect(row.locator('[data-line-status]')).toHaveText('تم استلامه');
        await row.locator('[data-act="notify"]').click();
        await page.locator('[data-confirm]').click();
        await expect(row.locator('[data-line-status]')).toHaveText('تم التبليغ');
        await row.locator('[data-act="attendance"]').click();
        await page.locator('[data-confirm]').click();
        await expect(row.locator('[data-line-status]')).toHaveText('تم حضوره');
    }
    await expect(page.locator('[data-request-status]')).toHaveText('مكتمل');
    expect(errors).toEqual([]);
});
