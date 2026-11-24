// Unit tests of the «الصفحات» search (resources/js/pageSearch.js engine + resources/js/pageIndex.js index),
// on the real sidebar and registry. Run: npm run test:js (node --test, no extra dependency).
// Route existence, parameters and permission mapping are checked against Laravel in PageSearchIndexTest.
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import navigation from '../../resources/js/navigation.js';
import { addMenu, viewMenu } from '../../resources/js/quickMenus.js';
import { buildPageEntries, candidatePages, EXTRA_PAGES, PAGE_DESCRIPTIONS, PAGE_KEYWORDS, PAGE_SYNONYMS, PAGE_WORD_FORMS, pageSearchFor, visibilityKey } from '../../resources/js/pageIndex.js';
import { createPageSearch, normalize, tokenize } from '../../resources/js/pageSearch.js';

const json = (file) => JSON.parse(readFileSync(new URL(`../../resources/js/locales/${file}`, import.meta.url)));
const ar = json('ar.json');
const en = json('en.json');
const dict = json('dict.en.json');
const get = (tree, key) => key.split('.').reduce((node, part) => (node && typeof node === 'object' ? node[part] : undefined), tree);

const navRoutes = navigation.flatMap((item) => (item.children ? item.children.map((c) => c.route) : [item.route]));
const sectionOf = (key) => navigation.find((item) => item.key === key);

/** The browser's environment, for a user who may open `allowed(route)` (default: everything, like the owner). */
function env({ allowed = () => true, features = ['vat_return', 'zatca', 'gosi', 'saudization'], exists = () => true, locale = 'ar', superAdmin = false } = {}) {
    const [main, other] = locale === 'ar' ? [ar, en] : [en, ar];
    return {
        t: (key) => get(main, key) || '',
        tOther: (key) => get(other, key) || '',
        plain: (text) => (locale === 'en' ? dict[text] || text : text),
        plainOther: (text) => (locale === 'en' ? text : dict[text] || ''),
        canRoute: allowed,
        hasFeature: (key) => features.includes(key),
        routeExists: exists,
        urlFor: (name) => `/${name.replaceAll('.', '/')}`,
        permissionOf: (name) => `${name.split('.')[0]}.x.view`,
        signedIn: true,
        superAdmin,
        separator: locale === 'ar' ? '، ' : ', ',
    };
}

const searchFor = (options) => createPageSearch(buildPageEntries(env(options)), { forms: PAGE_WORD_FORMS, synonyms: PAGE_SYNONYMS });
const owner = searchFor();
const pagesOf = (result) => result.items.flatMap((i) => (i.kind === 'section' ? i.children.map((c) => c.entry) : [i.entry]));
const first = (result) => result.items[0];

// ---------------------------------------------------------------- normalization

test('normalization: hamza, diacritics, tatweel, ta marbuta, alef maqsura, digits and spaces', () => {
    assert.equal(normalize('دفتر الأستاذ'), normalize('دفتر الاستاذ'));
    assert.equal(normalize('دَفْتَرُ الأُسْتَاذِ'), normalize('دفتر الاستاذ'));
    assert.equal(normalize('دفـــتر'), 'دفتر');
    assert.equal(normalize('إعدادات آلية'), 'اعدادات اليه');
    assert.equal(normalize('فاتورة'), normalize('فاتوره'));
    assert.equal(normalize('مستوى'), normalize('مستوي'));
    // ؤ and ئ seat the same hamza: «مسؤول» / «مسئول» and «شؤون» / «شئون» share a form.
    const shared = (a, b) => tokenize(a)[0].forms.some((f) => tokenize(b)[0].forms.includes(f));
    assert.ok(shared('مسؤول', 'مسئول'));
    assert.ok(shared('شؤون', 'شئون'));
    assert.ok(shared('القوائم', 'قوايم'));
    assert.equal(normalize('١٢٣ ۴۵'), '123 45');
    assert.equal(normalize('  القيود   /  اليومية  '), 'القيود اليوميه');
});

test('the article is told apart from a hamza: «إلغاء» is not «ال» + «غاء»', () => {
    const [cancel] = tokenize('إلغاء');
    assert.deepEqual(cancel.forms, ['الغاء']);
    const [withArticle] = tokenize('الإلغاء');
    assert.ok(withArticle.forms.includes('الغاء'));
    const [suppliers] = tokenize('للموردين');
    assert.ok(suppliers.forms.includes('موردين'));
});

// ---------------------------------------------------------------- the user's examples (T01–T06)

test('T01 an exact page name opens that page first', () => {
    const r = owner.search('دفتر الأستاذ');
    assert.equal(first(r).kind, 'page');
    assert.equal(first(r).entry.routeName, 'accounting.ledger.index');
    assert.equal(first(r).tier, 1);
    assert.deepEqual(first(r).entry.path, ['المحاسبة', 'دفتر الأستاذ']);
});

test('T02 without hamza, with diacritics or tatweel: the same page', () => {
    for (const q of ['دفتر الاستاذ', 'دَفْتَر الأُسْتاذ', 'دفـتر الاسـتاذ', 'الاستاذ']) {
        assert.equal(first(owner.search(q)).entry.routeName, 'accounting.ledger.index', q);
    }
    assert.equal(first(owner.search('الاعدادات العامه')).entry.routeName, 'settings.index');
});

test('T03 a section name shows the section with every page the user may open, in sidebar order', () => {
    const r = owner.search('المحاسبة');
    const group = first(r);
    assert.equal(group.kind, 'section');
    assert.equal(group.entry.key, 'accounting');
    assert.equal(group.expanded, true);
    assert.deepEqual(group.children.map((c) => c.entry.routeName), sectionOf('accounting').children.map((c) => c.route));
    // The section is a group, never a link.
    assert.equal(group.entry.url, null);
    // Its pages are inside the group, not listed a second time.
    const flatRoutes = r.items.filter((i) => i.kind === 'page').map((i) => i.entry.routeName);
    assert.ok(!flatRoutes.some((name) => name.startsWith('accounting.') && sectionOf('accounting').children.some((c) => c.route === name)));
    // The financial statements are «التقارير المحاسبية»: offered folded, under their own section.
    const statements = r.items.find((i) => i.kind === 'section' && i.entry.key === 'statements');
    assert.ok(statements && !statements.expanded);
});

test('T04 a menu (sidebar page) name: the page, then the pages registered under it', () => {
    const r = owner.search('الإعدادات العامة');
    assert.equal(first(r).entry.routeName, 'settings.index');
    const packs = r.items.find((i) => i.kind === 'page' && i.entry.routeName === 'settings.sector-packs');
    assert.ok(packs, 'sector packs (outside the sidebar) listed under its parent');
    assert.deepEqual(packs.entry.path, ['الإعدادات', 'الإعدادات العامة', 'حزم القطاعات']);
    assert.equal(packs.tier, 5);
});

test('T05 other names and word forms: الزبائن, قيود, الاستاذ العام, الموردون, فاتورة', () => {
    assert.equal(first(owner.search('الزبائن')).entry.routeName, 'sales.clients.index');
    assert.equal(first(owner.search('قيود')).entry.routeName, 'accounting.journals.index');
    assert.equal(first(owner.search('الاستاذ العام')).entry.routeName, 'accounting.ledger.index');
    assert.equal(first(owner.search('الموردون')).entry.routeName, 'purchases.suppliers.index');
    assert.equal(first(owner.search('موردين')).entry.routeName, 'purchases.suppliers.index');
    assert.equal(first(owner.search('مورد')).entry.routeName, 'purchases.suppliers.index');
    assert.equal(first(owner.search('فاتورة')).entry.routeName, 'sales.invoices.index');
    assert.equal(first(owner.search('سند قبض')).entry.routeName, 'finance.revenues.index');
    assert.equal(first(owner.search('General Ledger')).entry.routeName, 'accounting.ledger.index');
});

test('T06 a page that does not exist gives no result, not a made-up link', () => {
    for (const q of ['مجموعات الموردين', 'صفحة غير موجودة إطلاقا', 'INV-2026-0001']) {
        const r = owner.search(q);
        assert.equal(r.items.length, 0, q);
        assert.equal(r.total, 0, q);
    }
});

// ---------------------------------------------------------------- what may be indexed (T07–T10, T25, T26)

test('T07 a route that does not exist is not indexed', () => {
    const s = searchFor({ exists: (name) => name !== 'sales.customer-groups.index' });
    assert.ok(!pagesOf(s.search('مجموعات العملاء')).some((e) => e.routeName === 'sales.customer-groups.index'));
    assert.ok(!buildPageEntries(env({ exists: (name) => name !== 'sales.customer-groups.index' })).some((e) => e.routeName === 'sales.customer-groups.index'));
});

test('a page whose URL needs parameters is not indexed', () => {
    const e = env();
    e.urlFor = (name) => {
        if (name === 'accounting.ledger.index') throw new Error('Ziggy error: parameter required');
        return `/${name}`;
    };
    assert.ok(!buildPageEntries(e).some((x) => x.routeName === 'accounting.ledger.index'));
});

test('T08 pages the user may not open are not shown, by name or through their section', () => {
    const s = searchFor({ allowed: (name) => !name.startsWith('accounting.') });
    assert.equal(s.search('دفتر الأستاذ').items.length, 0);
    assert.ok(!s.search('المحاسبة').items.some((i) => i.kind === 'section' && i.entry.key === 'accounting'));
    assert.ok(!pagesOf(s.search('القيود')).some((e) => e.routeName.startsWith('accounting.')));
});

test('T09 / T10 a module switched off disappears; switched on, it is back', () => {
    // routePermissions leaves out the routes of an unavailable module, so canRoute is false for them.
    const off = searchFor({ allowed: (name) => !name.startsWith('manufacturing.') });
    assert.ok(!off.search('التصنيع').items.some((i) => i.entry.key === 'manufacturing'));
    assert.equal(off.search('أوامر الإنتاج').items.length, 0);
    const on = searchFor();
    assert.equal(first(on.search('التصنيع')).entry.key, 'manufacturing');
    assert.equal(first(on.search('أوامر الإنتاج')).entry.routeName, 'manufacturing.orders.index');
});

test('a country feature that is off hides its pages (vat_return)', () => {
    const s = searchFor({ features: [] });
    assert.ok(!pagesOf(s.search('إقرار ضريبة القيمة المضافة')).some((e) => e.routeName === 'accounting.reports.vat-return'));
    assert.ok(pagesOf(owner.search('إقرار ضريبة القيمة المضافة')).some((e) => e.routeName === 'accounting.reports.vat-return'));
});

test('T25 a registered page outside the sidebar shows when allowed', () => {
    assert.equal(first(owner.search('الإشعارات')).entry.routeName, 'notifications.index');
    assert.equal(first(owner.search('حزم القطاعات')).entry.routeName, 'settings.sector-packs');
    assert.equal(first(owner.search('كلمة المرور')).entry.routeName, 'profile.edit');
    assert.equal(first(owner.search('مطابقة ثلاثية')).entry.routeName, 'purchases.invoices.from-receipts');
    const hidden = searchFor({ allowed: (name) => name !== 'settings.sector-packs' });
    assert.equal(hidden.search('حزم القطاعات').items.length, 0);
    // The platform panel only for super admins.
    assert.equal(owner.search('لوحة الإدارة العامة').items.length, 0);
    assert.equal(first(searchFor({ superAdmin: true }).search('لوحة الإدارة العامة')).entry.routeName, 'super-admin.dashboard');
});

test('a page outside the sidebar whose parent the user may not open: no parent name in its path', () => {
    const s = searchFor({ allowed: (name) => name !== 'purchases.invoices.index' });
    const page = first(s.search('مطابقة ثلاثية')).entry;
    assert.equal(page.routeName, 'purchases.invoices.from-receipts');
    assert.deepEqual(page.path, ['المشتريات', 'فاتورة مورد مقابل الاستلامات']);
    assert.equal(page.parentTitle, '');
});

test('T26 a section without a page the user may open does not show', () => {
    const s = searchFor({ allowed: (name) => !name.startsWith('hr.') });
    assert.ok(!s.search('الموارد البشرية').items.some((i) => i.kind === 'section' && i.entry.key === 'hr'));
    assert.ok(!buildPageEntries(env({ allowed: (name) => !name.startsWith('hr.') })).some((e) => e.id === 'section:hr'));
});

// ---------------------------------------------------------------- results (T15, T22–T24, ranking, limits)

test('T15 pages with the same name show with their paths', () => {
    const r = owner.search('إقرار ضريبة القيمة المضافة');
    const same = r.items.filter((i) => i.kind === 'page' && i.entry.title === 'إقرار ضريبة القيمة المضافة');
    assert.equal(same.length, 2);
    assert.notDeepEqual(same[0].entry.path, same[1].entry.path);
    assert.deepEqual(same.map((i) => i.entry.path[0]).sort(), ['التقارير', 'القوائم المالية'].sort());
});

test('T22 markup in the query or in names is plain text: nothing is built from it', () => {
    for (const q of ['<img src=x onerror=alert(1)>', '"><script>alert(1)</script>', "'; DROP TABLE users; --", '{{ constructor.constructor("alert(1)")() }}']) {
        const r = owner.search(q);
        assert.equal(r.items.length, 0, q);
    }
    const s = createPageSearch([{ id: 'page:x', type: 'page', title: '<b>تقرير</b>', keywords: [], category: '', order: 0 }]);
    assert.equal(first(s.search('تقرير')).entry.title, '<b>تقرير</b>');
});

test('T11 / T12 / T13 / T23 the cached index follows the company, user, branch and permissions, never leaking', () => {
    // The browser keeps one index (pageIndex.js pageSearchFor): reused while nothing changed, rebuilt otherwise.
    const ownerEnv = env();
    const companyOne = pageSearchFor(ownerEnv, 'ar|company-1|user-1');
    assert.equal(pageSearchFor(env(), 'ar|company-1|user-1'), companyOne, 'same scope and pages: no rebuild');
    assert.equal(companyOne.search('دفتر الأستاذ').items.length, 1);

    // Another company (or user) with the same pages: its own index.
    const otherCompany = pageSearchFor(env(), 'ar|company-2|user-7');
    assert.notEqual(otherCompany, companyOne);

    // A role or permission change, or a branch whose modules differ: the visible pages change, so a new index.
    const cashier = pageSearchFor(env({ allowed: (name) => name.startsWith('pos.') || name === 'sales.clients.index' }), 'ar|company-2|user-7');
    assert.notEqual(cashier, otherCompany);
    assert.equal(cashier.search('دفتر الأستاذ').items.length, 0);
    assert.equal(cashier.search('المحاسبة').items.length, 0);
    assert.equal(first(cashier.search('العملاء')).entry.routeName, 'sales.clients.index');

    // A branch in a country without VAT: its country pages leave too.
    const noVat = pageSearchFor(env({ features: [] }), 'ar|company-2|user-7');
    assert.ok(!pagesOf(noVat.search('إقرار ضريبة القيمة المضافة')).some((e) => e.routeName === 'accounting.reports.vat-return'));

    // Back to the first company: its index again, rebuilt from its own pages.
    const again = pageSearchFor(ownerEnv, 'ar|company-1|user-1');
    assert.equal(again.search('دفتر الأستاذ').items.length, 1);
    assert.notEqual(visibilityKey(env()), visibilityKey(env({ allowed: (name) => !name.startsWith('accounting.') })));
});

test('T24 a large index is built once and searched fast', () => {
    const words = ['تقرير', 'فاتورة', 'حساب', 'مخزون', 'عميل', 'مورد', 'قيد', 'راتب', 'صنف', 'فرع', 'ضريبة', 'مشروع'];
    const big = [];
    for (let i = 0; i < 5000; i++) {
        big.push({ id: `page:p${i}`, type: 'page', title: `${words[i % 12]} ${words[(i * 7) % 12]} رقم${i}`, keywords: [`${words[(i * 5) % 12]} إضافي`], category: `قسم ${i % 40}`, description: 'وصف قصير للصفحة', order: i });
    }
    let t = performance.now();
    const s = createPageSearch(big, { forms: PAGE_WORD_FORMS, synonyms: PAGE_SYNONYMS });
    const buildMs = performance.now() - t;
    t = performance.now();
    let found = 0;
    for (const q of ['تقرير', 'فاتورة حساب', 'الموردين', 'رقم49', 'غير موجود', 'قيود', 'الضرائب', 'مخز']) found += s.search(q).total;
    const searchMs = (performance.now() - t) / 8;
    assert.ok(found > 0);
    assert.ok(buildMs < 3000, `build ${buildMs.toFixed(0)} ms`);
    assert.ok(searchMs < 300, `search ${searchMs.toFixed(0)} ms per query`);
    // The real index: a keystroke costs well under a frame.
    t = performance.now();
    for (let i = 0; i < 50; i++) owner.search(i % 2 ? 'دفتر الاستاذ' : 'المحاسبة');
    assert.ok((performance.now() - t) / 50 < 16, 'real index search under 16 ms');
});

test('ranking: exact name, starts with, contains, keyword, parent page, section, description', () => {
    const entries = [
        { id: 'section:s', type: 'section', title: 'المبيعات', keywords: [], category: '', order: 0, children: ['page:sec'] },
        { id: 'page:desc', type: 'page', title: 'صفحة أخرى', keywords: [], category: 'قسم', description: 'تفاصيل التسوية هنا', order: 1 },
        { id: 'page:kw', type: 'page', title: 'كشف البنك', keywords: ['التسوية'], category: 'قسم', order: 2 },
        { id: 'page:partial', type: 'page', title: 'قائمة التسوية', keywords: [], category: 'قسم', order: 3 },
        { id: 'page:prefix', type: 'page', title: 'التسوية البنكية', keywords: [], category: 'قسم', order: 4 },
        { id: 'page:exact', type: 'page', title: 'التسوية', keywords: [], category: 'قسم', order: 5 },
        { id: 'page:parent', type: 'page', title: 'إعداد', keywords: [], category: 'قسم', parentTitle: 'التسوية', level: 3, order: 6 },
        { id: 'page:sec', type: 'page', title: 'شيء', keywords: [], category: 'التسوية', sectionId: 'section:none', order: 7 },
    ];
    const r = createPageSearch(entries).search('التسوية');
    assert.deepEqual(r.items.map((i) => i.entry.id), ['page:exact', 'page:prefix', 'page:partial', 'page:kw', 'page:parent', 'page:sec', 'page:desc']);
    assert.deepEqual(r.items.map((i) => i.tier), [1, 2, 3, 4, 5, 6, 7]);
});

test('every page appears once: in its section group or in the list, never twice', () => {
    for (const q of ['المبيعات', 'التقارير', 'الإعدادات', 'العملاء', 'المخزون', 'المالية']) {
        const ids = pagesOf(owner.search(q, { limit: 500 })).map((e) => e.id);
        assert.equal(new Set(ids).size, ids.length, q);
    }
    const entries = buildPageEntries(env({ superAdmin: true }));
    const ids = entries.map((e) => e.id);
    assert.equal(new Set(ids).size, ids.length);
});

test('too short a query asks for more letters; a limit gives «show more»', () => {
    assert.equal(owner.search('ا').tooShort, true);
    assert.equal(owner.search('ال').tooShort, true);
    assert.equal(owner.search('  ').tooShort, true);
    const r = owner.search('عميل', { limit: 3 });
    assert.equal(r.items.filter((i) => i.kind === 'page').length, 3);
    assert.ok(r.more > 0);
    assert.equal(r.total, r.more + 3);
});

test('little words are ignored in the query and in names; alone they ask for more', () => {
    assert.equal(first(owner.search('العمولة على المبيعات')).entry.routeName, 'sales.commissions.index');
    assert.equal(first(owner.search('دفتر في الأستاذ')).entry.routeName, 'accounting.ledger.index');
    for (const q of ['على', 'في من', 'and the']) assert.equal(owner.search(q).tooShort, true, q);
    const s = searchFor({ locale: 'en' });
    const chart = first(s.search('Chart of accounts'));
    assert.equal(chart.entry.routeName, 'accounting.accounts.index');
    assert.equal(chart.tier, 1);
});

test('short words are whole words, except the last one being typed: «P&L» is not «p…» + «l…»', () => {
    const s = searchFor({ locale: 'en' });
    const r = s.search('P&L');
    assert.equal(first(r).entry.routeName, 'accounting.reports.income-statement');
    assert.ok(!pagesOf(r).some((e) => e.routeName === 'sales.price-lists.index'));
    // The last word may still be a start: «دفتر ا» while typing «دفتر الأستاذ».
    assert.equal(first(owner.search('دفتر ا')).entry.routeName, 'accounting.ledger.index');
});

test('«شئون» and «شؤون», «الميزانية» and «الموازنة» go where an accountant expects', () => {
    assert.equal(first(owner.search('شئون الموظفين')).entry.key, 'hr');
    assert.equal(first(owner.search('شؤون الموظفين')).entry.key, 'hr');
    assert.equal(first(owner.search('الميزانية')).entry.routeName, 'accounting.reports.balance-sheet');
    const budget = owner.search('الموازنة');
    assert.equal(first(budget).entry.routeName, 'accounting.budgets.index');
    assert.ok(!pagesOf(budget).some((e) => e.routeName === 'accounting.reports.balance-sheet'));
});

test('a page registered under a sidebar page takes that page\'s country feature', () => {
    EXTRA_PAGES.push({ route: 'test.vat-extra', label: 'nav.withholding', parent: 'accounting.reports.vat-return' });
    try {
        const has = (features) => buildPageEntries(env({ features })).some((e) => e.routeName === 'test.vat-extra');
        assert.equal(has(['vat_return']), true);
        assert.equal(has([]), false);
        assert.ok(candidatePages().find((p) => p.route === 'test.vat-extra').feature === 'vat_return');
    } finally {
        EXTRA_PAGES.pop();
    }
});

test('the English interface searches English names and still finds Arabic ones', () => {
    const s = searchFor({ locale: 'en' });
    assert.equal(first(s.search('General Ledger')).entry.routeName, 'accounting.ledger.index');
    assert.equal(first(s.search('ledger')).entry.routeName, 'accounting.ledger.index');
    assert.equal(first(s.search('دفتر الاستاذ')).entry.routeName, 'accounting.ledger.index');
    assert.equal(first(s.search('Accounting')).entry.key, 'accounting');
});

// ---------------------------------------------------------------- the index itself

test('every entry has the index fields; sections have no link and pages no record data', () => {
    const entries = buildPageEntries(env({ superAdmin: true }));
    const known = new Set([...navRoutes, ...EXTRA_PAGES.map((p) => p.route)]);
    for (const e of entries) {
        for (const field of ['id', 'title', 'category', 'parent', 'routeName', 'keywords', 'description', 'permission', 'module']) {
            assert.ok(field in e, `${e.id} has ${field}`);
        }
        assert.ok(e.title, `${e.id} has a title`);
        if (e.type === 'section') {
            assert.equal(e.url, null);
            assert.ok(e.children.length > 0);
        } else {
            assert.ok(known.has(e.routeName), `${e.routeName} comes from the sidebar or EXTRA_PAGES`);
            assert.equal(e.url, `/${e.routeName.replaceAll('.', '/')}`, 'URL built from the route name alone, no record id');
        }
    }
    assert.equal(entries.filter((e) => e.type === 'section').length, navigation.filter((i) => i.children).length);
});

test('the registry names only pages of the index, once each', () => {
    const indexed = new Set(candidatePages().map((p) => p.route));
    for (const route of [...Object.keys(PAGE_KEYWORDS), ...Object.keys(PAGE_DESCRIPTIONS)]) {
        assert.ok(indexed.has(route), `registry names ${route}, which is not in the index`);
    }
    for (const extra of EXTRA_PAGES) {
        assert.ok(!navRoutes.includes(extra.route), `${extra.route} is in the sidebar already`);
        if (extra.parent) assert.ok(navRoutes.includes(extra.parent), `${extra.route}: parent ${extra.parent} is not a sidebar page`);
        assert.ok(get(ar, extra.label) && get(en, extra.label), `${extra.label} in both languages`);
    }
    assert.equal(new Set(navRoutes).size, navRoutes.length, 'a sidebar route appears twice');
});

test('the «إضافة» and «عرض» menus are read, not changed', () => {
    const before = JSON.stringify([addMenu.map((g) => [g.label, g.items.map((i) => [i.label, i.route])]), viewMenu.map((i) => [i.label, i.route])]);
    buildPageEntries(env());
    owner.search('العملاء');
    const after = JSON.stringify([addMenu.map((g) => [g.label, g.items.map((i) => [i.label, i.route])]), viewMenu.map((i) => [i.label, i.route])]);
    assert.equal(after, before);
    // Their labels are other names of the same pages: «عميل» (إضافة) and «سندات القبض» (عرض).
    const clients = buildPageEntries(env()).find((e) => e.routeName === 'sales.clients.index');
    assert.ok(clients.keywords.includes('عميل'));
    const receipts = buildPageEntries(env()).find((e) => e.routeName === 'finance.revenues.index');
    assert.ok(receipts.keywords.includes('سندات القبض'));
});
