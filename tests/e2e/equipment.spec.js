import { test, expect } from '@playwright/test';
import { pickFromLookup, watchErrors } from './helpers';

test('equipment: plan, daily reading, maintenance order with storekeeper issue', async ({ page }) => {
    const errors = watchErrors(page);
    const code = `E2E-${Date.now().toString().slice(-6)}`;

    // New equipment with an hour meter.
    await page.goto('/equipment');
    await page.getByRole('button', { name: 'معدة جديدة' }).click();
    const dialog = page.locator('div.fixed.inset-0').last();
    await dialog.getByLabel('رمز المعدة').fill(code);
    await dialog.getByLabel('اسم المعدة').fill('شيول اختبار');
    await dialog.getByLabel('قراءة الساعات الحالية').fill('1000');
    await dialog.getByLabel('سعر الساعة').fill('150');
    await dialog.getByRole('button', { name: 'حفظ' }).click();
    await expect(page).toHaveURL(/\/equipment\/\d+$/);
    const equipmentUrl = page.url();

    // Periodic item: an inventory product every 250 hours.
    await page.getByRole('button', { name: '+ بند صيانة دورية' }).click();
    const plan = page.locator('div.fixed.inset-0').last();
    await plan.getByLabel('اسم البند').fill('تغيير زيت المحرك');
    await pickFromLookup(page, plan.getByPlaceholder('ابحث باسم الصنف أو رمزه أو الباركود...'), 'شاشة');
    await plan.getByLabel('ساعات تشغيل').fill('250');
    await plan.getByRole('button', { name: 'حفظ' }).click();
    await expect(plan).toBeHidden();
    await expect(page.locator('tbody')).toContainText('تغيير زيت المحرك');
    await expect(page.locator('tbody')).toContainText('سليم');

    // Daily reading pushes it into the warning margin (240 of 250 h used).
    await page.goto('/equipment/readings');
    const row = page.locator('tr', { hasText: code });
    await row.getByLabel(`عداد ساعات ${code}`).fill('1240');
    await row.getByLabel(`ساعات عمل ${code}`).fill('8');
    await page.getByRole('button', { name: 'حفظ القراءات' }).click();
    await expect(page.locator('body')).toContainText('تم حفظ 1 قراءة');

    await page.goto(equipmentUrl);
    await expect(page.locator('tbody')).toContainText('يستحق قريباً');

    // Maintenance order from the equipment card: the due item is pre-selected.
    await page.getByRole('link', { name: 'أمر صيانة' }).click();
    const order = page.locator('div.fixed.inset-0').last();
    await order.getByLabel('الفني / الورشة').fill('ورشة الاختبار');
    await order.getByRole('button', { name: 'فتح الأمر' }).click();
    await expect(page).toHaveURL(/\/equipment\/maintenance\/\d+$/);
    await expect(page.getByRole('checkbox', { name: /تغيير زيت المحرك/ })).toBeChecked();

    // Storekeeper issue of the item's part.
    await page.getByRole('button', { name: 'إضافة أصناف البنود المحددة' }).click();
    await page.getByRole('button', { name: 'صرف وخصم من المخزون' }).click();
    await expect(page.locator('body')).toContainText('تم صرف الأصناف وخصمها من المخزون');
    await expect(page.locator('body')).toContainText('صُرف');

    await page.getByRole('button', { name: /إكمال الصيانة/ }).click();
    await expect(page.locator('body')).toContainText('تم إكمال الصيانة');

    await page.goto(equipmentUrl);
    await expect(page.locator('tbody').first()).toContainText('سليم');
    await expect(page.locator('body')).toContainText('متاحة');

    await page.goto('/equipment/maintenance/dashboard');
    await expect(page.locator('h1')).toContainText('لوحة الصيانة');
    await page.goto('/equipment/reports?type=compliance');
    await expect(page.locator('tbody')).toContainText(code);
    expect(errors).toEqual([]);
});
