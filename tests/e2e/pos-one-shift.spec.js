import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { watchErrors } from './helpers.js';

/*
 * One open shift per cashier. A shift in another branch is out of sight on the POS screen, so the refusal says where
 * it is. Two shifts left open from before the rule are closed one after the other, each from a fresh screen.
 */
const tinker = (code) => execFileSync('php', ['artisan', 'tinker', `--execute=${code}`], {
    env: { ...process.env, DB_CONNECTION: 'sqlite', DB_DATABASE: `${process.cwd()}/database/e2e.sqlite`, APP_ENV: 'local', CACHE_STORE: 'array' }, encoding: 'utf8',
}).trim().split('\n').pop();

const asOwner = (body) => tinker(`$u = App\\Models\\User::where('email', 'anas@diwan.io')->first(); app(App\\Services\\TenantManager::class)->runAs(App\\Models\\Company::find($u->company_id), function () use ($u) { ${body} });`);

const openShifts = "App\\Models\\PosSession::withoutGlobalScopes()->where('user_id', $u->id)->where('status', 'opened')";
const closeAllShifts = () => asOwner(`${openShifts}->update(['status' => 'closed', 'closed_at' => now()]); echo 'ok';`);
const addRegister = (name, branchCode) => asOwner(`$b = App\\Models\\Branch::where('code', '${branchCode}')->firstOrFail(); $w = App\\Models\\Warehouse::orderByDesc('is_main')->firstOrFail(); App\\Models\\PosRegister::create(['name' => '${name}', 'branch_id' => $b->id, 'warehouse_id' => $w->id, 'is_active' => true]); echo 'ok';`);
const shiftOn = (name, hoursAgo) => asOwner(`$r = App\\Models\\PosRegister::withoutGlobalScopes()->where('name', '${name}')->firstOrFail(); $at = now()->subHours(${hoursAgo}); $s = App\\Models\\PosSession::create(['company_id' => $r->company_id, 'branch_id' => $r->branch_id, 'user_id' => $u->id, 'pos_register_id' => $r->id, 'opening_cash' => 0, 'status' => 'opened', 'opened_at' => $at]); $s->forceFill(['created_at' => $at])->save(); echo 'ok';`);

test('pos: a second shift is refused with where the first one is, and two old shifts close one after the other', async ({ page }) => {
    test.setTimeout(120_000);
    const errors = watchErrors(page);
    const sfx = String(Date.now()).slice(-5);
    const [elsewhere, older, newer] = [`جهاز جدة ${sfx}`, `جهاز أقدم ${sfx}`, `جهاز أحدث ${sfx}`];
    closeAllShifts();
    addRegister(elsewhere, 'BR-02');
    addRegister(older, 'BR-01');
    addRegister(newer, 'BR-01');

    shiftOn(elsewhere, 8);
    await page.goto('/pos', { waitUntil: 'networkidle' });
    const open = page.getByRole('button', { name: 'فتح الجلسة' });
    await open.click();
    await expect(page.getByText(`لديك وردية مفتوحة على «${elsewhere}» (فرع المنطقة الغربية - جدة)؛ أغلقها أولًا.`)).toBeVisible();
    await expect(open).toBeVisible();

    closeAllShifts();
    shiftOn(older, 5);
    shiftOn(newer, 1);
    await page.goto('/pos', { waitUntil: 'networkidle' });
    const register = page.locator('p.text-3xs').first();
    const dialog = page.getByRole('heading', { name: 'إغلاق جلسة الكاشير' });
    const closeShift = async () => {
        await page.getByRole('button', { name: 'إغلاق الجلسة' }).click();
        await page.getByRole('button', { name: 'إغلاق وتسوية' }).click();
    };

    await expect(register).toContainText(newer);
    await closeShift();
    await expect(register).toContainText(older);
    await expect(dialog).toBeHidden();

    await closeShift();
    await expect(open).toBeVisible();
    expect(asOwner(`echo ${openShifts}->count();`)).toBe('0');
    expect(errors).toEqual([]);
});
