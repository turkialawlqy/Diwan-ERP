import { test, expect } from '@playwright/test';
import { totp, watchErrors } from './helpers';

// Fresh session on the central domain, where the platform admin signs in.
test.use({ storageState: { cookies: [], origins: [] }, baseURL: `http://localhost:${process.env.E2E_PORT || 8077}` });

test('platform admin is forced to enrol, then signs in with a code', async ({ page }) => {
    const errors = watchErrors(page);
    const login = async () => {
        await page.goto('/login');
        await page.locator('input[type=email]').fill('admin@diwan.io');
        await page.locator('input[type=password]').fill('admin123456');
        await page.locator('button[type=submit]').click();
    };

    await login();
    await page.goto('/super-admin/dashboard');
    await expect(page).toHaveURL(/\/profile\?two_factor=required/);
    await expect(page.locator('body')).toContainText('التحقق الثنائي إلزامي لحسابك');

    const section = page.locator('#two-factor');
    await section.locator('input[type=password]').fill('admin123456');
    await section.getByRole('button', { name: 'تفعيل التحقق الثنائي' }).click();
    const secret = (await section.locator('code').innerText()).replace(/\s+/g, '');
    await expect(section.locator('img')).toBeVisible();
    await section.getByPlaceholder('123456').fill(totp(secret));
    await section.getByRole('button', { name: 'تأكيد التفعيل' }).click();
    await expect(section.locator('ul li')).toHaveCount(8);
    await section.getByRole('button', { name: 'حفظتها، متابعة' }).click();

    await page.goto('/super-admin/dashboard');
    await expect(page).toHaveURL(/\/super-admin\/dashboard/);

    // Sign out and back in: now the code is required.
    await page.context().clearCookies();
    await login();
    await expect(page).toHaveURL(/two-factor-challenge/);
    await page.locator('#code').fill('000000');
    await page.getByRole('button', { name: 'تحقق ودخول' }).click();
    await expect(page.locator('body')).toContainText('الرمز غير صحيح');
    await page.locator('#code').fill(totp(secret, 1));
    await page.getByRole('button', { name: 'تحقق ودخول' }).click();
    await expect(page).not.toHaveURL(/two-factor-challenge|login/);
    expect(errors.filter((e) => !/status of 422/.test(e))).toEqual([]);
});
