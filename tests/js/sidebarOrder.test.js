// The side menu's order (resources/js/sidebarOrder.js, used by AppSidebar.vue): «الفروع», «قوالب الطباعة»,
// «الإعدادات» and «التحديثات والطلبات» always close it, in this order, whatever order the user saved; every other
// group (a module the company switches on later, a group added to navigation.js) renders above them.
// Run: npm run test:js (node --test, no extra dependency).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import navigation from '../../resources/js/navigation.js';
import { orderSidebar, PINNED_TAIL, reorder, reorderGroups } from '../../resources/js/sidebarOrder.js';

const ar = JSON.parse(readFileSync(new URL('../../resources/js/locales/ar.json', import.meta.url)));
const en = JSON.parse(readFileSync(new URL('../../resources/js/locales/en.json', import.meta.url)));
const get = (tree, key) => key.split('.').reduce((node, part) => (node && typeof node === 'object' ? node[part] : undefined), tree);

const keys = (items) => items.map((item) => item.key);
const tailOf = (items) => keys(items).slice(-PINNED_TAIL.length);
const groupOf = (key) => navigation.find((item) => item.key === key);
const others = navigation.filter((item) => !PINNED_TAIL.includes(item.key));

test('the menu closes with «الفروع», «قوالب الطباعة», «الإعدادات», «التحديثات والطلبات», in this order', () => {
    assert.deepEqual(PINNED_TAIL, ['branches', 'templates', 'settings', 'updates']);
    assert.deepEqual(PINNED_TAIL.map((key) => groupOf(key)?.label), ['nav.branches', 'nav.templates', 'nav.settings', 'nav.updates']);
    assert.deepEqual(PINNED_TAIL.map((key) => get(ar, groupOf(key).label)), ['الفروع', 'قوالب الطباعة', 'الإعدادات', 'التحديثات والطلبات']);
    for (const key of PINNED_TAIL) assert.ok(get(en, groupOf(key).label), `nav label of ${key} has an English text`);

    assert.deepEqual(tailOf(orderSidebar(navigation, null)), PINNED_TAIL, 'the default order');
    assert.deepEqual(tailOf(orderSidebar(navigation, { groups: [] })), PINNED_TAIL, 'an empty saved order');
});

test('a saved order does not move them: one saved before the rule, with them on top, still ends with them', () => {
    const saved = { groups: [...[...PINNED_TAIL].reverse(), ...keys(others).reverse()] };
    const ordered = orderSidebar(navigation, saved);
    assert.deepEqual(tailOf(ordered), PINNED_TAIL);
    assert.deepEqual(keys(ordered).slice(0, others.length), keys(others).reverse(), 'the other groups keep the saved order');
});

test('no other group renders below them, whatever the saved order', () => {
    for (const group of others) {
        const savedOrders = [
            [...PINNED_TAIL, group.key], // saved as the very last group
            [group.key, ...PINNED_TAIL].reverse(),
            keys(others).filter((key) => key !== group.key), // missing from the order: switched on after it was saved
        ];
        for (const groups of savedOrders) {
            const ordered = orderSidebar(navigation, { groups });
            assert.deepEqual(tailOf(ordered), PINNED_TAIL, `${group.key} with the saved order ${groups.join(',')}`);
            assert.equal(ordered.length, navigation.length, 'nothing is lost or repeated');
        }
    }
});

test('a module switched on after the order was saved shows right above them, the saved order kept', () => {
    for (const module of ['manufacturing', 'hr', 'pos', 'equipment']) {
        const before = navigation.filter((item) => item.key !== module);
        const saved = { groups: keys(orderSidebar(before, null)).reverse() };
        const after = keys(orderSidebar(navigation, saved));
        assert.deepEqual(after.slice(-PINNED_TAIL.length), PINNED_TAIL, module);
        assert.equal(after.at(-PINNED_TAIL.length - 1), module, `${module} is right above «الفروع»`);
        assert.deepEqual(after.slice(0, -PINNED_TAIL.length - 1), saved.groups.filter((key) => !PINNED_TAIL.includes(key)));
    }
});

test('a group added to navigation.js after them still renders above them', () => {
    const future = { key: 'future_module', label: 'nav.future', children: [{ label: 'nav.future', route: 'future.index', path: '/future' }] };
    for (const saved of [null, { groups: keys(navigation) }, { groups: ['future_module', ...PINNED_TAIL] }]) {
        const ordered = keys(orderSidebar([...navigation, future], saved));
        assert.deepEqual(ordered.slice(-PINNED_TAIL.length), PINNED_TAIL);
        assert.ok(ordered.indexOf('future_module') < ordered.length - PINNED_TAIL.length);
    }
});

test('those the user may not open are left out, the rest keep their order at the bottom', () => {
    const visible = navigation.filter((item) => !['branches', 'templates'].includes(item.key));
    const ordered = keys(orderSidebar(visible, { groups: ['updates', 'settings', 'sales'] }));
    assert.deepEqual(ordered.slice(-2), ['settings', 'updates']);
    assert.equal(ordered[0], 'sales');
    assert.equal(ordered.length, visible.length);
});

test('links inside a group follow the saved order, inside the pinned groups too', () => {
    const saved = { groups: ['sales'], children: { settings: ['users.index', 'settings.index'], sales: ['sales.clients.index'] } };
    const ordered = orderSidebar(navigation, saved);
    const settings = ordered.find((item) => item.key === 'settings');
    assert.deepEqual(settings.children.slice(0, 2).map((link) => link.route), ['users.index', 'settings.index']);
    assert.equal(settings.children.length, groupOf('settings').children.length);
    assert.equal(ordered.find((item) => item.key === 'sales').children[0].route, 'sales.clients.index');
    assert.deepEqual(groupOf('settings').children[0].route, 'settings.index', 'navigation.js itself is not changed');
});

test('editing the order: the pinned groups keep their place and no group is moved below them', () => {
    const draft = orderSidebar(navigation, null).map((item) => ({ key: item.key }));
    const firstPinned = draft.length - PINNED_TAIL.length;
    assert.equal(reorderGroups(draft, firstPinned - 1, firstPinned), false, 'the last other group does not go down into them');
    assert.equal(reorderGroups(draft, 0, draft.length - 1), false, 'nor is it dropped on one of them');
    assert.equal(reorderGroups(draft, firstPinned, firstPinned - 1), false, 'a pinned group does not go up');
    assert.equal(reorderGroups(draft, draft.length - 1, firstPinned), false, 'nor change places with another pinned group');
    assert.deepEqual(tailOf(draft), PINNED_TAIL);

    assert.equal(reorderGroups(draft, 1, 0), true, 'the other groups still move');
    assert.equal(draft[0].key, navigation[1].key);
    assert.equal(reorderGroups(draft, 0, firstPinned - 1), true);
    assert.deepEqual(tailOf(orderSidebar(navigation, { groups: keys(draft) })), PINNED_TAIL, 'what is saved reads back with them last');
});

test('reorder moves an item to a place and refuses places outside the list', () => {
    const list = ['a', 'b', 'c'];
    assert.equal(reorder(list, 0, 2), true);
    assert.deepEqual(list, ['b', 'c', 'a']);
    for (const [from, to] of [[0, 3], [-1, 0], [3, 0], [1, 1], [0, -1]]) assert.equal(reorder(list, from, to), false);
    assert.deepEqual(list, ['b', 'c', 'a']);
});

test('the side menu orders its groups and edits the order with these rules (AppSidebar.vue)', () => {
    const sidebar = readFileSync(new URL('../../resources/js/Components/AppSidebar.vue', import.meta.url), 'utf8');
    assert.match(sidebar, /orderSidebar\(visibleItems\.value, savedOrder\.value\)/);
    assert.match(sidebar, /v-for="item in orderedItems"/);
    assert.match(sidebar, /reorderGroups\(draft\.value, /);
    assert.doesNotMatch(sidebar, /\.sort\(/, 'the order is decided in sidebarOrder.js only');
});
