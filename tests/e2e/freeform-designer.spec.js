import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

test('free-form designer: create, drag, add a field, save, print an invoice with it', async ({ page }) => {
    const errors = watchErrors(page);
    const name = `تصميم حر ${Date.now().toString().slice(-5)}`;

    await page.goto('/templates');
    await page.getByRole('button', { name: 'إنشاء قالب جديد' }).click();
    await page.getByPlaceholder('مثال: قالب الفاتورة الهندسية الحديثة').fill(name);
    await page.getByRole('radio', { name: /تصميم حر/ }).click();
    await page.getByLabel('المستند').selectOption('invoice');
    await page.getByRole('button', { name: 'إنشاء وفتح المصمم' }).click();
    await expect(page).toHaveURL(/\/templates\/\d+\/designer$/);
    await expect(page.locator('[data-type="table"]')).toBeVisible();

    // Drag the title box 20 mm to the left and 10 mm down.
    const title = page.locator('[data-el]').filter({ hasText: 'فاتورة ضريبية' }).first();
    await title.click();
    const x = page.getByLabel(/^أفقي/);
    const y = page.getByLabel(/^رأسي/);
    const [x0, y0] = [Number(await x.inputValue()), Number(await y.inputValue())];
    const box = await title.boundingBox();
    const mmPx = box.width / 74; // the title is 74 mm wide
    await page.mouse.move(box.x + box.width / 2, box.y + box.height / 2);
    await page.mouse.down();
    await page.mouse.move(box.x + box.width / 2 - 20 * mmPx, box.y + box.height / 2 + 10 * mmPx, { steps: 8 });
    await page.mouse.up();
    await expect.poll(async () => Math.round(x0 - Number(await x.inputValue()))).toBeGreaterThanOrEqual(18);
    expect(Math.round(Number(await y.inputValue()) - y0)).toBeGreaterThanOrEqual(8);

    // Arrow keys nudge, the properties panel restyles.
    await page.getByRole('button', { name: 'عريض' }).click();

    // Add a labelled field from the fields tab.
    await page.getByRole('tab', { name: 'الحقول' }).click();
    await page.getByRole('button', { name: 'رقم المبنى', exact: true }).nth(1).click(); // customer group (the first is the company's)
    await expect(page.getByLabel('محتوى النص')).toHaveValue('رقم المبنى: {{party.building_number}}');

    // Undo removes it, redo brings it back.
    await page.locator('main').click({ position: { x: 5, y: 5 } });
    await page.keyboard.press('Control+z');
    await page.keyboard.press('Control+y');

    await page.getByRole('button', { name: /^حفظ/ }).click();
    await expect(page.getByRole('status')).toContainText('تم حفظ التصميم');

    // PDF preview of the unsaved/saved design.
    const preview = page.waitForResponse((r) => r.url().includes('/preview') && r.request().method() === 'POST');
    const popup = page.waitForEvent('popup');
    await page.getByRole('button', { name: 'معاينة PDF' }).click();
    expect((await preview).status()).toBe(200);
    await (await popup).close();

    await page.getByRole('button', { name: 'استخدامه في الطباعة' }).click();
    await expect(page.getByText('مستخدم في الطباعة', { exact: true })).toBeVisible();

    // An invoice now shows and prints this design (server PDF).
    await page.goto('/sales/invoices');
    const pdf = page.waitForResponse((r) => /\/documents\/invoice\/\d+\/pdf/.test(r.url()));
    await page.locator('tbody tr a').first().click();
    await expect(page.locator('iframe[title="معاينة الفاتورة بالتصميم الحر"]')).toBeVisible();
    expect((await pdf).headers()['content-type']).toContain('application/pdf');

    // Leave the quick A4 template as the invoice default for the other tests.
    await page.goto('/templates');
    const quick = page.locator('div.group').filter({ hasText: 'فاتورة ضريبية A4 (ZATCA)' }).first();
    await quick.getByTitle('تعيين كافتراضي').click();
    await expect(quick.getByText('افتراضي', { exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});
