import { test as setup, expect } from '@playwright/test';

// Signs in once as the demo company owner and stores the session for all tests.
setup('sign in', async ({ page }) => {
    await page.goto('/login');
    await page.locator('input[type=email]').fill('anas@diwan.io');
    await page.locator('input[type=password]').fill('password');
    await page.locator('button[type=submit]').click();
    await expect(page).not.toHaveURL(/\/login/);
    await page.context().storageState({ path: 'tests/e2e/.auth/user.json' });
});
