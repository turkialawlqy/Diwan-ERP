import { test, expect } from '@playwright/test';
import { watchErrors } from './helpers';

/*
 * «أبرز التحديثات»: releases grouped by month and filtered on the page by the year and month of
 * their date, the kind of change and words. Expectations are read from the page itself, so they
 * hold as config/release_notes.php grows.
 */
const shownLines = (page) => page.locator('[data-release]').evaluateAll((articles) => articles.flatMap((a) =>
    [...a.querySelectorAll('li[data-type]')].map((li) => ({ version: a.dataset.release, date: a.dataset.date, type: li.dataset.type, text: li.innerText }))));
const count = (n) => n.toLocaleString('en-US');
// The page's search: hamza, ta marbuta, alef maqsura and diacritics do not matter.
const norm = (s) => s.toLowerCase().replace(/[\u064B-\u0652\u0670\u0640]/g, '').replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي');

test('release notes: grouped by month, filtered by year, month, kind and words', async ({ page }) => {
    const errors = watchErrors(page);
    await page.goto('/whats-new', { waitUntil: 'networkidle' });
    const all = await shownLines(page);
    expect(all.length).toBeGreaterThan(0);
    await expect(page.locator('[data-kind="all"] span')).toHaveText(count(all.length));

    // Newest month first, each release under the month of its date.
    const months = await page.locator('section[data-month]').evaluateAll((sections) => sections.map((s) => ({
        key: s.dataset.month,
        dates: [...s.querySelectorAll('[data-release]')].map((a) => a.dataset.date),
    })));
    expect(months.map((m) => m.key)).toEqual(months.map((m) => m.key).sort().reverse());
    months.forEach((m) => m.dates.forEach((d) => expect(d.slice(0, 7)).toBe(m.key)));

    // The year, then the month of the oldest release.
    const [year, month] = all.map((l) => l.date).sort()[0].split('-');
    await page.locator('[data-notes-year]').selectOption(year);
    expect(await shownLines(page)).toEqual(all.filter((l) => l.date.startsWith(`${year}-`)));
    await page.locator('[data-notes-month]').selectOption(month);
    const inMonth = all.filter((l) => l.date.startsWith(`${year}-${month}-`));
    expect(await shownLines(page)).toEqual(inMonth);
    await expect(page.locator('section[data-month]')).toHaveCount(1);

    // A kind within that month: its button counts the month's lines of that kind.
    const kind = inMonth[0].type;
    const ofKind = inMonth.filter((l) => l.type === kind);
    await expect(page.locator(`[data-kind="${kind}"] span`)).toHaveText(count(ofKind.length));
    await page.locator(`[data-kind="${kind}"]`).click();
    await expect(page.locator(`[data-kind="${kind}"]`)).toHaveAttribute('aria-pressed', 'true');
    expect(await shownLines(page)).toEqual(ofKind);

    // Month back to «الكل»: the whole year, still of that kind.
    await page.locator('[data-notes-month]').selectOption('');
    expect(await shownLines(page)).toEqual(all.filter((l) => l.date.startsWith(`${year}-`) && l.type === kind));
    await page.locator('[data-kind="all"]').click();

    // Words written without hamza or ta marbuta still match.
    const word = all.flatMap((l) => l.text.split(/[\s«»()،؛:.]+/)).find((w) => /[أإآة]/.test(w) && w.length > 3);
    const typed = word.replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه');
    await page.locator('[data-notes-year]').selectOption('');
    await page.getByPlaceholder('ابحث في التحديثات…').fill(typed);
    const found = await shownLines(page);
    expect(found.length).toBeGreaterThan(0);
    expect(found).toEqual(all.filter((l) => norm(`${l.text} v${l.version}`).includes(norm(typed))));

    // Nothing matches: say so, and «مسح التصفية» brings everything back.
    await page.getByPlaceholder('ابحث في التحديثات…').fill('لا يوجد هذا النص zzz');
    await expect(page.locator('[data-notes-empty]')).toContainText('لا توجد تحديثات تطابق التصفية');
    await page.locator('[data-notes-empty] button').click();
    expect(await shownLines(page)).toEqual(all);
    await expect(page.locator('[data-notes-year]')).toHaveValue('');
    await expect(page.locator('[data-notes-month]')).toHaveValue('');

    expect(errors).toEqual([]);
});

test('opening the release notes clears the red dot', async ({ page }) => {
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    await expect(page.locator('[data-notes-dot]')).toBeVisible();
    await page.goto('/whats-new', { waitUntil: 'networkidle' });
    await page.goto('/dashboard', { waitUntil: 'networkidle' });
    await expect(page.locator('[data-notes-dot]')).toHaveCount(0);
});
