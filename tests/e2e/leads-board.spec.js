import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

test('leads board: dragging a lead to «خسارة» asks for the reason first', async ({ page }) => {
    const errors = watchErrors(page);
    const name = `فرصة ${Date.now().toString().slice(-6)}`;

    await page.setViewportSize({ width: 2000, height: 900 }); // all the columns side by side: the drag needs no scrolling
    await page.goto('/crm/leads');
    await page.getByRole('button', { name: 'عميل محتمل' }).click();
    const form = page.locator('div.fixed.inset-0').last();
    await form.getByLabel('الاسم').fill(name);
    await form.getByRole('button', { name: 'حفظ' }).click();
    await expect(page).toHaveURL(/\/crm\/leads\/\d+$/);

    await page.goto('/crm/leads');
    const lost = page.locator('div.min-w-60').filter({ hasText: 'خسارة' });
    await page.getByRole('link', { name }).dragTo(lost);

    const dialog = page.locator('div.fixed.inset-0').last();
    await expect(dialog).toContainText('سبب خسارة الفرصة');
    await expect(lost).not.toContainText(name);

    await dialog.getByRole('button', { name: 'حفظ' }).click();
    await expect(dialog.locator('p.text-rose-600, .text-rose-600').first()).toBeVisible();
    await expect(lost).not.toContainText(name);

    await dialog.getByLabel('سبب الخسارة').fill('السعر مرتفع');
    await dialog.getByRole('button', { name: 'حفظ' }).click();
    await expect(dialog).toBeHidden();
    await expect(lost).toContainText(name);
    expect(errors).toEqual([]);
});
