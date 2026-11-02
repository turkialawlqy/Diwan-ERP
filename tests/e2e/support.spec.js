import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers.js';

/* Support tickets: open one, answer it (the status follows), resolve it, and get the survey link; categories in the settings. */
test('support: open, reply, resolve a ticket and manage categories', async ({ page }) => {
    const errors = watchErrors(page);

    await page.goto('/support/settings', { waitUntil: 'networkidle' });
    await page.locator('[data-category-name]').fill('أعطال الأجهزة');
    await page.locator('[data-add-category]').click();
    await expect(page.locator('[data-category="أعطال الأجهزة"]')).toBeVisible();
    await expect(page.locator('[data-sla-row="urgent"]')).toBeVisible();

    await page.goto('/support/tickets/create', { waitUntil: 'networkidle' });
    await page.getByRole('textbox').nth(1).fill('زائر الاختبار'); // contact name (customer picker is the first field)
    await page.locator('[data-subject]').fill('الطابعة لا تعمل');
    await page.locator('[data-save-ticket]').click();

    await expect(page.locator('[data-status]')).toHaveText('مفتوحة');
    await expect(page.locator('[data-sla]')).toContainText('الرد الأول');

    await page.locator('[data-reply-body]').fill('أرسلنا فنيًا إلى موقعكم');
    await page.locator('[data-send-reply]').click();
    await expect(page.locator('[data-message="public"]')).toContainText('أرسلنا فنيًا');
    await expect(page.locator('[data-status]')).toHaveText('بانتظار العميل');

    await page.locator('[data-reply-type="internal"]').check({ force: true });
    await page.locator('[data-reply-body]').fill('ملاحظة داخلية للفريق');
    await page.locator('[data-send-reply]').click();
    await expect(page.locator('[data-message="internal"]')).toBeVisible();

    await page.locator('[data-resolve]').click();
    await expect(page.locator('[data-status]')).toHaveText('محلولة');
    await expect(page.locator('[data-survey]')).toBeVisible();
    await expect(page.locator('[data-reopen]')).toBeVisible();

    await page.goto('/support/tickets?status=all', { waitUntil: 'networkidle' });
    await expect(page.locator('[data-ticket]').filter({ hasText: 'الطابعة لا تعمل' })).toBeVisible();
    await expect(page.locator('[data-metrics]')).toBeVisible();

    expect(errors).toEqual([]);
});
