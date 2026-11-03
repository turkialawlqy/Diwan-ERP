import { test, expect } from '@playwright/test';
import { watchErrors, pickFromLookup } from './helpers.js';

/* Contracts: a template, a contract with an obligation, approval (no rule: at once), a new text version, termination. */
test('contracts: template, contract with an obligation, activation, new version and termination', async ({ page }) => {
    const errors = watchErrors(page);

    await page.goto('/contracts/templates/create', { waitUntil: 'networkidle' });
    await page.locator('[data-template-name]').fill('عقد صيانة قياسي');
    await page.locator('[data-template-body]').fill('1. يلتزم الطرف الأول بالصيانة الشهرية.\n2. يسدد الطرف الثاني القيمة في موعدها.');
    await page.locator('[data-save-template]').click();
    await expect(page.locator('[data-template="عقد صيانة قياسي"]')).toBeVisible();

    await page.goto('/contracts/create', { waitUntil: 'networkidle' });
    await page.locator('[data-title]').fill('عقد صيانة المكتب');
    await pickFromLookup(page, page.getByPlaceholder('ابحث بالاسم').first(), 'ع');
    await page.locator('[data-add-obligation]').click();
    await page.locator('[data-ob-title="0"]').fill('الدفعة الأولى');
    await page.locator('[data-body]').fill('نص العقد الأول');
    await page.locator('[data-save-contract]').click();

    await expect(page.locator('[data-status]')).toHaveText('مسودة');
    await expect(page.locator('[data-obligation="الدفعة الأولى"]')).toBeVisible();

    await page.locator('[data-submit]').click();
    await expect(page.locator('[data-status]')).toHaveText('ساري');
    await expect(page.locator('[data-versions]')).toContainText('v1');

    await page.locator('[data-amend]').click();
    await page.locator('[data-amend-body]').fill('نص العقد بعد الملحق الأول');
    await page.locator('[data-amend-note]').fill('ملحق 1');
    await page.locator('[data-confirm-amend]').click();
    await expect(page.locator('[data-contract-body]')).toContainText('بعد الملحق الأول');
    await expect(page.locator('[data-versions]')).toContainText('v2');

    await page.locator('[data-toggle-obligation]').click();
    await expect(page.locator('[data-obligation="الدفعة الأولى"]')).toContainText('تم');

    await page.locator('[data-terminate]').click();
    await page.locator('[data-terminate-reason]').fill('انتهاء الحاجة');
    await page.locator('[data-confirm-terminate]').click();
    await expect(page.locator('[data-status]')).toHaveText('مفسوخ');

    await page.goto('/contracts', { waitUntil: 'networkidle' });
    await expect(page.locator('[data-contract-stats]')).toBeVisible();
    await expect(page.locator('[data-contract]').filter({ hasText: 'عقد صيانة المكتب' })).toBeVisible();

    expect(errors).toEqual([]);
});
