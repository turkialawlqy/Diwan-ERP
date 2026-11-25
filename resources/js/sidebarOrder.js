/**
 * The order of the side menu (AppSidebar.vue).
 *
 * «الفروع», «قوالب الطباعة», «الإعدادات» and «التحديثات والطلبات» close the menu, always in this order. This is a
 * rule, not a place in navigation.js: every other group renders above them, whether it is a module the company
 * switches on (Settings → Modules), a group added to navigation.js later (even after them) or a group the user
 * moved. The user's own order (users.sidebar_order, «تعديل القائمة الجانبية») orders the other groups and the
 * links inside every group; an order saved before this rule, with one of these higher up, reads the same way.
 *
 * Plain JavaScript (no Vue): tests/js/sidebarOrder.test.js runs it with node --test.
 */
export const PINNED_TAIL = ['branches', 'templates', 'settings', 'updates'];

export const isPinned = (group) => PINNED_TAIL.includes(group?.key);

/** `list` in the saved `order` (a list of keys); items missing from it keep their default place, after the rest. */
export function sortBy(list, order, keyOf) {
    if (!order?.length) return list;
    const rank = new Map(order.map((key, i) => [key, i]));
    return list
        .map((item, i) => [item, rank.has(keyOf(item)) ? rank.get(keyOf(item)) : 1e6 + i])
        .sort((a, b) => a[1] - b[1])
        .map(([item]) => item);
}

/**
 * The groups the user may see (`items`: navigation.js filtered by permission) in the order the menu shows them:
 * the other groups in the saved order (one missing from it, such as a module switched on after the order was
 * saved, comes after them), then the pinned tail. The links inside each group follow the saved order.
 */
export function orderSidebar(items, saved) {
    const others = sortBy(items.filter((item) => !isPinned(item)), saved?.groups, (item) => item.key);
    const tail = PINNED_TAIL.map((key) => items.find((item) => item.key === key)).filter(Boolean);
    return [...others, ...tail].map((item) => (item.children
        ? { ...item, children: sortBy(item.children, saved?.children?.[item.key], (child) => child.route) }
        : item));
}

/** Moves list[from] to place `to`; false when there is nothing to move. */
export function reorder(list, from, to) {
    if (from < 0 || from >= list.length || to < 0 || to >= list.length || from === to) return false;
    const [moved] = list.splice(from, 1);
    list.splice(to, 0, moved);
    return true;
}

/** Moves a group while the order is edited (`list` as orderSidebar gives it): the pinned groups keep their place. */
export function reorderGroups(list, from, to) {
    if (isPinned(list[from]) || isPinned(list[to])) return false;
    return reorder(list, from, to);
}
